<?php
/**
 * Media library index synced to media-index.json at the repo root.
 *
 * The index is a discovery tool for authoring product/post markdown
 * files in git: it maps every WordPress attachment ID to its source
 * URL, file path, mime type, alt text, and title. When you upload a
 * new image in WP, the next request flushes a fresh index to GitHub
 * so you can pull and reference its ID in a markdown file.
 *
 * @package Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Media_Index
 */
class Wordpress_GitHub_Sync_Media_Index {

    const PATH = 'media-index.json';

    /**
     * Application container.
     *
     * @var Wordpress_GitHub_Sync
     */
    protected $app;

    /**
     * Whether a flush has been scheduled for this request.
     *
     * @var bool
     */
    protected $dirty = false;

    public function __construct( Wordpress_GitHub_Sync $app ) {
        $this->app = $app;
    }

    /**
     * Marks the index dirty for this request and registers a shutdown flush.
     */
    public function mark_dirty() {
        if ( $this->dirty ) {
            return;
        }
        $this->dirty = true;
        add_action( 'shutdown', array( $this, 'flush' ), 100 );
    }

    /**
     * Builds an index entry for a single attachment.
     *
     * @param int $attachment_id
     *
     * @return array|null
     */
    public function entry_for( $attachment_id ) {
        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return null;
        }

        $url = wp_get_attachment_url( $attachment_id );
        $file = get_post_meta( $attachment_id, '_wp_attached_file', true );

        return array(
            'url'   => $url ? $url : '',
            'file'  => $file ? $file : '',
            'mime'  => $post->post_mime_type,
            'alt'   => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'title' => $post->post_title,
        );
    }

    /**
     * Pushes a fresh index to GitHub, rebuilt from the current media library.
     *
     * Called on shutdown after attachments mutate, and from the WP-CLI
     * rebuild command. Idempotent — if content is unchanged, GitHub's
     * contents API returns 422 / no commit lands.
     *
     * @return true|WP_Error
     */
    public function flush() {
        $entries = $this->build_full_index();
        $content = $this->serialize( $entries );

        return $this->push( $content, count( $entries ) );
    }

    /**
     * Builds the complete index from the WordPress media library.
     *
     * @return array
     */
    protected function build_full_index() {
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );

        $entries = array();
        foreach ( $query->get_posts() as $id ) {
            $entry = $this->entry_for( $id );
            if ( $entry ) {
                $entries[ (string) $id ] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Renders entries to JSON with stable ordering.
     *
     * @param array $entries
     *
     * @return string
     */
    protected function serialize( $entries ) {
        ksort( $entries, SORT_NUMERIC );
        return wp_json_encode( $entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    }

    /**
     * Creates or updates the index file in the repo. Skips the API call
     * entirely when the rendered content matches the last-pushed sha
     * cached in the wghs_media_index_sha option.
     *
     * @param string $content
     * @param int    $count
     *
     * @return true|WP_Error
     */
    protected function push( $content, $count ) {
        $api = $this->app->api();

        // Look up the existing file to get its sha (required for updates).
        $existing = $api->fetch()->blob_by_path( self::PATH );
        $existing_sha = ( $existing instanceof Wordpress_GitHub_Sync_Blob ) ? $existing->sha() : '';

        if ( $existing instanceof Wordpress_GitHub_Sync_Blob && $existing->content() === $content ) {
            return true;
        }

        $data = new stdClass;
        $data->path    = self::PATH;
        $data->content = $content;
        $data->sha     = $existing_sha;
        $blob = new Wordpress_GitHub_Sync_Blob( $data );

        $message = sprintf( 'Update media index (%d attachments)', $count );

        if ( $existing_sha ) {
            $result = $api->persist()->update_file( $blob, $message );
        } else {
            $result = $api->persist()->create_file( $blob, $message );
        }

        if ( is_wp_error( $result ) ) {
            error_log( sprintf( '[wghs] media index push failed: %s', $result->get_error_message() ) );
        }

        return $result;
    }
}
