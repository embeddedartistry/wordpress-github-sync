<?php
/**
 * Plugin Name: Embedded Artistry Wordpress-GitHub Sync
 * Plugin URI: https://github.com/embeddedartistry/wordpress-github-sync
 * Description: Synchronize published content between wordpress and GitHub
 * Version: 1.0
 * Author:  Embedded Artistry
 * Author URI: https://embeddedartistry.com
 * License: GPLv2
 * Text Domain: wordpress-github-sync
 */

// If the functions have already been autoloaded, don't reload.
// This fixes function duplication during unit testing.
$path = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $path ) ) {
    require_once( $path );
}

add_action( 'plugins_loaded', array( new Wordpress_GitHub_Sync, 'boot' ) );

class Wordpress_GitHub_Sync {

    /**
     * Object instance
     * @var self
     */
    public static $instance;

    /**
     * Language text domain
     * @var string
     */
    public static $text_domain = 'wordpress-github-sync';

    /**
     * Controller object
     * @var Wordpress_GitHub_Sync_Controller
     */
    public $controller;

    /**
     * Controller object
     * @var Wordpress_GitHub_Sync_Admin
     */
    public $admin;

    /**
     * CLI object.
     *
     * @var Wordpress_GitHub_Sync_CLI
     */
    protected $cli;

    /**
     * Request object.
     *
     * @var Wordpress_GitHub_Sync_Request
     */
    protected $request;

    /**
     * Response object.
     *
     * @var Wordpress_GitHub_Sync_Response
     */
    protected $response;

    /**
     * Api object.
     *
     * @var Wordpress_GitHub_Sync_Api
     */
    protected $api;

    /**
     * Import object.
     *
     * @var Wordpress_GitHub_Sync_Import
     */
    protected $import;

    /**
     * Export object.
     *
     * @var Wordpress_GitHub_Sync_Export
     */
    protected $export;

    /**
     * Semaphore object.
     *
     * @var Wordpress_GitHub_Sync_Semaphore
     */
    protected $semaphore;

    /**
     * Database object.
     *
     * @var Wordpress_GitHub_Sync_Database
     */
    protected $database;

    /**
     * Called at load time, hooks into WP core
     */
    public function __construct() {
        self::$instance = $this;

        if ( is_admin() ) {
            $this->admin = new Wordpress_GitHub_Sync_Admin( plugin_basename( __FILE__ ) );
        }

        $this->controller = new Wordpress_GitHub_Sync_Controller( $this );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'wghs', $this->cli() );
        }
    }

    /**
     * Attaches the plugin's hooks into WordPress.
     */
    public function boot() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'admin_notices', array( $this, 'activation_notice' ) );

        add_action( 'init', array( $this, 'l10n' ) );

        // Controller actions.
        add_action( 'save_post', array( $this->controller, 'export_post' ) );
        add_action( 'delete_post', array( $this->controller, 'delete_post' ) );
        add_action( 'wp_ajax_nopriv_wghs_push_request', array( $this->controller, 'pull_posts' ) );
        add_action( 'wghs_export', array( $this->controller, 'export_all' ), 10, 2 );
        add_action( 'wghs_import', array( $this->controller, 'import_master' ), 10, 2 );
        // Uncomment below to enable the "edit" button going to GitHub. I don't want it right now.
        // The reason being the Wordpress view gives me the control I need for things like
        // field atlas, courses, tooltips, etc.
        //add_filter( 'get_edit_post_link', array( $this, 'edit_post_link' ), 10, 3 );

        // add_filter( 'wghs_post_meta', array( $this, 'ignore_post_meta' ), 10, 1 );
        // add_filter( 'wghs_pre_import_meta', array( $this, 'ignore_post_meta' ), 10, 1 );
        add_filter( 'the_content', array( $this, 'the_content' ) );

        do_action( 'wghs_boot', $this );
    }

    public function edit_post_link($link, $postID, $context) {
        if ( ! wp_is_post_revision( $postID ) ) {
            $post = new Wordpress_GitHub_Sync_Post( $postID, Wordpress_GitHub_Sync::$instance->api() );
            if ( $post->is_on_github() ) {
                return $post->github_edit_url();
            }
        }

        return $link;
    }

    public function ignore_post_meta($meta) {
        $ignore_meta_keys = get_option('wghs_ignore_metas');
        if (empty($ignore_meta_keys)) {
            return $meta;
        }

        $keys = preg_split("/\\r\\n|\\r|\\n/", $ignore_meta_keys);
        if (empty($keys)) {
            return $meta;
        }
        foreach ($keys as $key => $value) {
            $keys[$key] = trim($value);
        }

        foreach ($meta as $key => $value) {
            if (in_array($key, $keys)) {
                unset($meta[$key]);
            }
        }

        return $meta;
    }

    public function the_content($content) {
        $arr = wp_upload_dir();
        $baseurl = $arr['baseurl'] . '/wordpress-github-sync';
        $basedir = $arr['basedir'] . '/wordpress-github-sync';

        $content = preg_replace_callback(
            '/(<img [^>]*?src=[\'"])\S*?(\/images\/\S+)([\'"].*?>)/',
            function($matchs) use ($baseurl, $basedir) {
                if (is_file($basedir . $matchs[2])) {
                    $url = $baseurl . $matchs[2];
                    return "{$matchs[1]}$url{$matchs[3]}";
                }
                return "{$matchs[0]}";
            },
            $content
        );

        $content = preg_replace_callback(
            '/(<a [^>]*?href=[\'"])\S*?(\/images\/S+)\s*([\'"].*?>)/',
            function($matchs) use ($baseurl, $basedir) {
                if (is_file($basedir . $matchs[2])) {
                    $url = $baseurl . $matchs[2];
                    return "{$matchs[1]}$url{$matchs[3]}";
                }
                return "{$matchs[0]}";
            },
            $content
        );
        return $content;
    }

    /**
     * Init i18n files
     */
    public function l10n() {
        load_plugin_textdomain( self::$text_domain );
    }

    /**
     * Sets and kicks off the export cronjob
     */
    public function start_export( $force = false ) {
        $this->start_cron( 'export', $force );
    }

    /**
     * Sets and kicks off the import cronjob
     */
    public function start_import( $force = false ) {
        $this->start_cron( 'import', $force );
    }

    /**
     * Enables the admin notice on initial activation
     */
    public function activate() {
        if ( 'yes' !== get_option( '_wghs_fully_exported' ) ) {
            set_transient( '_wghs_activated', 'yes' );
        }
    }

    /**
     * Displays the activation admin notice
     */
    public function activation_notice() {
        if ( ! get_transient( '_wghs_activated' ) ) {
            return;
        }

        delete_transient( '_wghs_activated' );

        ?><div class="updated">
            <p>
                <?php
                    printf(
                        __( 'To set up your site to sync with GitHub, update your <a href="%s">settings</a> and click "Export to GitHub."', 'wordpress-github-sync' ),
                        admin_url( 'options-general.php?page=' . static::$text_domain)
                    );
                ?>
            </p>
        </div><?php
    }

    /**
     * Get the Controller object.
     *
     * @return Wordpress_GitHub_Sync_Controller
     */
    public function controller() {
        return $this->controller;
    }

    /**
     * Lazy-load the CLI object.
     *
     * @return Wordpress_GitHub_Sync_CLI
     */
    public function cli() {
        if ( ! $this->cli ) {
            $this->cli = new Wordpress_GitHub_Sync_CLI;
        }

        return $this->cli;
    }

    /**
     * Lazy-load the Request object.
     *
     * @return Wordpress_GitHub_Sync_Request
     */
    public function request() {
        if ( ! $this->request ) {
            $this->request = new Wordpress_GitHub_Sync_Request( $this );
        }

        return $this->request;
    }

    /**
     * Lazy-load the Response object.
     *
     * @return Wordpress_GitHub_Sync_Response
     */
    public function response() {
        if ( ! $this->response ) {
            $this->response = new Wordpress_GitHub_Sync_Response( $this );
        }

        return $this->response;
    }

    /**
     * Lazy-load the Api object.
     *
     * @return Wordpress_GitHub_Sync_Api
     */
    public function api() {
        if ( ! $this->api ) {
            $this->api = new Wordpress_GitHub_Sync_Api( $this );
        }

        return $this->api;
    }

    /**
     * Lazy-load the Import object.
     *
     * @return Wordpress_GitHub_Sync_Import
     */
    public function import() {
        if ( ! $this->import ) {
            $this->import = new Wordpress_GitHub_Sync_Import( $this );
        }

        return $this->import;
    }

    /**
     * Lazy-load the Export object.
     *
     * @return Wordpress_GitHub_Sync_Export
     */
    public function export() {
        if ( ! $this->export ) {
            $this->export = new Wordpress_GitHub_Sync_Export( $this );
        }

        return $this->export;
    }

    /**
     * Lazy-load the Semaphore object.
     *
     * @return Wordpress_GitHub_Sync_Semaphore
     */
    public function semaphore() {
        if ( ! $this->semaphore ) {
            $this->semaphore = new Wordpress_GitHub_Sync_Semaphore;
        }

        return $this->semaphore;
    }

    /**
     * Lazy-load the Database object.
     *
     * @return Wordpress_GitHub_Sync_Database
     */
    public function database() {
        if ( ! $this->database ) {
            $this->database = new Wordpress_GitHub_Sync_Database( $this );
        }

        return $this->database;
    }

    /**
     * Print to WP_CLI if in CLI environment or
     * write to debug.log if WP_DEBUG is enabled
     * @source http://www.stumiller.me/sending-output-to-the-wordpress-debug-log/
     *
     * @param mixed $msg
     * @param string $write
     */
    public static function write_log( $msg, $write = 'line' ) {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            if ( is_array( $msg ) || is_object( $msg ) ) {
                WP_CLI::print_value( $msg );
            } else {
                WP_CLI::$write( $msg );
            }
        } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            if ( is_array( $msg ) || is_object( $msg ) ) {
                error_log( print_r( $msg, true ) );
            } else {
                error_log( $msg );
            }
        }
    }

    /**
     * Kicks of an import or export cronjob.
     *
     * @param bool   $force
     * @param string $type
     */
    protected function start_cron( $type, $force = false ) {
        update_option( '_wghs_' . $type . '_started', 'yes' );
        $user_id = get_current_user_id();
        wp_schedule_single_event( time(), 'wghs_' . $type . '', array( $user_id, $force ) );
        spawn_cron();
    }
}
