<?php
/**
 * Controller object manages tree retrieval, manipulation and publishing
 * @package Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Controller
 */
class Wordpress_GitHub_Sync_Controller {

    /**
     * Application container.
     *
     * @var Wordpress_GitHub_Sync
     */
    public $app;

    /**
     * Instantiates a new Controller object
     *
     * @param Wordpress_GitHub_Sync $app Applicatio container.
     */
    public function __construct( Wordpress_GitHub_Sync $app ) {
        $this->app = $app;
    }

    /**
     * Webhook callback as triggered from GitHub push.
     *
     * Reads the Webhook payload and syncs posts as necessary.
     *
     * @return boolean
     */
    public function pull_posts() {
        $this->set_ajax();
        if ( ! $this->app->semaphore()->is_open() ) {
            return $this->app->response()->error( new WP_Error(
                'semaphore_locked',
                sprintf( __( '%s : Semaphore is locked, import/export already in progress.', 'wordpress-github-sync' ), 'Controller::pull_posts()' )
            ) );
        }

        if ( ! $this->app->request()->is_secret_valid() ) {
            return $this->app->response()->error( new WP_Error(
                'invalid_headers',
                __( 'Failed to validate secret.', 'wordpress-github-sync' )
            ) );
        }

        // ping
        if ( $this->app->request()->is_ping() ) {
            return $this->app->response()->success( __( 'Wordpress is ready.', 'wordpress-github-sync' ) );
        }

        // push
        if ( ! $this->app->request()->is_push() ) {
            return $this->app->response()->error( new WP_Error(
                'invalid_headers',
                sprintf( 'Failed to validate webhook event: %s.',
                    $this->app->request()->webhook_event() )
            ) );
        }
        $payload = $this->app->request()->payload();

        $error = $payload->should_import();
        if ( is_wp_error( $error ) ) {
            /*　@var WP_Error $error */
            return $this->app->response()->error( $error );
        }

        $this->app->semaphore()->lock();
        remove_action( 'save_post', array( $this, 'export_post' ) );
        remove_action( 'delete_post', array( $this, 'delete_post' ) );

        // Here we set the user ID to the configured default so that when we
        // import the post, it is done with the same permissions as the initial export.
        // This prevents problems with things like Heapless C++ course modules
        // working in a forced export, but not when we export the lesson page.
        wp_set_current_user( get_option( 'wghs_default_user' ) );

        $result = $this->app->import()->payload( $payload );

        $this->app->semaphore()->unlock();

        if ( is_wp_error( $result ) ) {
            /*　@var WP_Error $result */
            return $this->app->response()->error( $result );
        }

        return $this->app->response()->success( $result );
    }

    /**
     * Imports posts from the current master branch.
     * @param  integer $user_id
     * @param  boolean $force
     * @return boolean
     */
    public function import_master( $user_id = 0, $force = false ) {
        if ( ! $this->app->semaphore()->is_open() ) {
            return $this->app->response()->error( new WP_Error(
                'semaphore_locked',
                sprintf( __( '%s : Semaphore is locked, import/export already in progress.', 'wordpress-github-sync' ), 'Controller::import_master()' )
            ) );
        }

        $this->app->semaphore()->lock();
        remove_action( 'save_post', array( $this, 'export_post' ) );
        remove_action( 'delete_post', array( $this, 'delete_post' ) );

        if ( $user_id ) {
            wp_set_current_user( $user_id );
        }

        $result = $this->app->import()->master( $force );

        $this->app->semaphore()->unlock();

        if ( is_wp_error( $result ) ) {
            /*　@var WP_Error $result */
            update_option( '_wghs_import_error', $result->get_error_message() );

            return $this->app->response()->error( $result );
        }

        update_option( '_wghs_import_complete', 'yes' );

        return $this->app->response()->success( $result );
    }

    /**
     * Export all the posts in the database to GitHub.
     *
     * @param  int        $user_id
     * @param  boolean    $force
     * @return boolean
     */
    public function export_all( $user_id = 0, $force = false ) {
        if ( ! $this->app->semaphore()->is_open() ) {
            return $this->app->response()->error( new WP_Error(
                'semaphore_locked',
                sprintf( __( '%s : Semaphore is locked, import/export already in progress.', 'wordpress-github-sync' ), 'Controller::export_all()' )
            ) );
        }

        $this->app->semaphore()->lock();

        if ( $user_id ) {
            wp_set_current_user( $user_id );
        }

        $result = $this->app->export()->full($force);
        $this->app->semaphore()->unlock();

        // Maybe move option updating out of this class/upgrade message display?
        if ( is_wp_error( $result ) ) {
            /*　@var WP_Error $result */
            update_option( '_wghs_export_error', $result->get_error_message() );

            return $this->app->response()->error( $result );
        } else {
            update_option( '_wghs_export_complete', 'yes' );
            update_option( '_wghs_fully_exported', 'yes' );

            return $this->app->response()->success( $result );
        }
    }

    /**
     * Exports a single post to GitHub by ID.
     *
     * Called on the save_post hook.
     *
     * @param int $post_id Post ID.
     *
     * @return boolean
     */
    public function export_post( $post_id ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $this->app->semaphore()->is_open() ) {
            return $this->app->response()->error( new WP_Error(
                'semaphore_locked',
                sprintf( __( '%s : Semaphore is locked, import/export already in progress.', 'wordpress-github-sync' ), 'Controller::export_post()' )
            ) );
        }

        // Here we set the user ID to the configured default so that when we
        // export the post, it is done with the same permissions as the initial export.
        // This prevents problems with things like Heapless C++ course modules
        // working in a forced export, but not when we export the lesson page.
        wp_set_current_user( get_option( 'wghs_default_user' ) );

        $this->app->semaphore()->lock();
        $result = $this->app->export()->update( $post_id );
        $this->app->semaphore()->unlock();

        if ( is_wp_error( $result ) ) {
            /*　@var WP_Error $result */
            return $this->app->response()->error( $result );
        }

        return $this->app->response()->success( $result );
    }

    /**
     * Removes the post from the tree.
     *
     * Called the delete_post hook.
     *
     * @param int $post_id Post ID.
     *
     * @return boolean
     */
    public function delete_post( $post_id ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $this->app->semaphore()->is_open() ) {
            return $this->app->response()->error( new WP_Error(
                'semaphore_locked',
                sprintf( __( '%s : Semaphore is locked, import/export already in progress.', 'wordpress-github-sync' ), 'Controller::delete_post()' )
            ) );
        }

        $this->app->semaphore()->lock();
        $result = $this->app->export()->delete( $post_id );
        $this->app->semaphore()->unlock();

        if ( is_wp_error( $result ) ) {
            /*　@var WP_Error $result */
            return $this->app->response()->error( $result );
        }

        return $this->app->response()->success( $result );
    }

    /**
     * Indicates we're running our own AJAX hook
     * and thus should respond with JSON, rather
     * than just returning data.
     */
    protected function set_ajax() {
        if ( ! defined( 'WOGH_AJAX' ) ) {
            define( 'WOGH_AJAX', true );
        }
    }
}
