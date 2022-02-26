<?php
/**
 * GitHub Export Manager.
 *
 * @package Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Export
 */
class Wordpress_GitHub_Sync_Export {

    /**
     * Application container.
     *
     * @var Wordpress_GitHub_Sync
     */
    protected $app;

    /**
     * Initializes a new export manager.
     *
     * @param Wordpress_GitHub_Sync $app Application container.
     */
    public function __construct( Wordpress_GitHub_Sync $app ) {
        $this->app = $app;
    }

    /**
     * Updates all of the current posts in the database on master.
     *
     * @param  bool    $force
     *
     * @return string|WP_Error
     */
    public function full( $force = false ) {
        $posts = $this->app->database()->fetch_all_supported( $force );

        if ( is_wp_error( $posts ) ) {
            /*　@var WP_Error $posts */
            return $posts;
        }

        $error = '';

        foreach ( $posts as $post ) {
            $result = $this->update( $post->id() );
            if ( is_wp_error( $result ) ) {
                /* @var WP_Error $result */
                $error = wghs_append_error( $error, $result );
            }
        }

        if ( is_wp_error( $error ) ) {
            /* @var WP_Error $error */
            return $error;
        }

        return __( 'Export to GitHub completed successfully.', 'wordpress-github-sync' );
    }


    /**
     * Check if it exists in github
     * @param  int  $post_id
     * @return boolean
     */
    protected function github_path( $post_id ) {
        $github_path = get_post_meta( $post_id, '_wghs_github_path', true );

        if ( $github_path && $this->app->api()->fetch()->exists( $github_path ) ) {
            return $github_path;
        }

        return false;
    }

    /**
     * Updates the provided post ID in master.
     *
     * @param int $post_id Post ID to update.
     *
     * @return string|WP_Error
     */
    public function update( $post_id ) {
        $post = $this->app->database()->fetch_by_id( $post_id );

        if ( is_wp_error( $post ) ) {
            /*　@var WP_Error $post */
            return $post;
        }

        if ( 'trash' === $post->status() ) {
            return $this->delete( $post_id );
        }

        if ( $old_github_path = $this->github_path( $post->id() ) ) {
            $post->set_old_github_path($old_github_path);
        }

        // Here we set the user ID to the configured default so that when we
        // export the post, it is done with the same permissions as the initial export.
        // This prevents problems with things like Heapless C++ course modules
        // working in a forced export, but not when we export the lesson page.
        // (note to self: if it doesn't work here, put it in update())
        $current_user = wp_get_current_user();
        wp_set_current_user( get_option( 'wghs_default_user' ) );

        $result = $this->export_post( $post );

        wp_set_current_user($current_user);

        if ( is_wp_error( $result ) ) {
            /* @var WP_Error $result */
            return $result;
        }

        return __( 'Export to GitHub completed successfully.', 'wordpress-github-sync' );
    }

    /**
     * Post to blob
     * @param  Wordpress_GitHub_Sync_Post $post
     * @return WP_Error|Wordpress_GitHub_Sync_Blob
     */
    protected function post_to_blob( Wordpress_GitHub_Sync_Post $post ) {
        if ( ! $post->get_blob()
            && $post->old_github_path()
            && wghs_is_dont_export_content() ) {


            $blob = $this->app->api()->fetch()->blob_by_path( $post->old_github_path() );

            if ( is_wp_error( $blob ) ) {
                /** @var WP_Error $blob */
                return $blob;
            }

            $post->set_blob( $blob );
        }

        return $post->to_blob();
    }

    /**
     * Export post to github
     * @param  Wordpress_GitHub_Sync_Post $post
     * @return WP_Error|true
     */
    public function export_post( Wordpress_GitHub_Sync_Post $post ) {
        // check blob
        $blob = $this->post_to_blob( $post );
        if ( is_wp_error( $blob ) ) {
            /** @var WP_Error $blob */
            return $blob;
        }

        $result = false;

        $persist = $this->app->api()->persist();
        $github_path = $post->github_path();
        $old_github_path = $post->old_github_path();

        if ( $old_github_path && $old_github_path != $github_path ) {
            // rename
            $message = apply_filters(
                'wghs_commit_msg_move_post',
                sprintf(
                    'Move %s to %s via %s (two part commit)',
                    $old_github_path, $github_path,
                    site_url()
                )
            ) . $this->get_commit_msg_tag();

            $result = $persist->delete_file( $post->old_github_path(), $blob->sha(), $message );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $result = $persist->create_file( $blob, $message );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        } elseif ( ! $old_github_path ) {
            // create new
            $message = apply_filters(
                'wghs_commit_msg_new_post',
                sprintf(
                    'Create %s from %s',
                    $github_path,
                    site_url()
                )
            ) . $this->get_commit_msg_tag();
            $result = $persist->create_file( $blob, $message );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        } elseif ( $old_github_path && $old_github_path == $github_path ) {
            // update
            $sha = wghs_git_sha( $blob->content() );
            if ( $sha === $blob->sha() ) {
                // don't export when has not changed
                return true;
            }
            $message = apply_filters(
                'wghs_commit_msg_update_post',
                sprintf(
                    'Update %s from %s',
                    $github_path,
                    site_url()
                )
            ) . $this->get_commit_msg_tag();
            $result = $persist->update_file( $blob, $message );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        $sha = $result->content->sha;
        $post->set_sha( $sha );
        $post->set_old_github_path( $github_path );

        return true;
    }

    /**
     * Deletes a provided post ID from master.
     *
     * @param int $post_id Post ID to delete.
     *
     * @return string|WP_Error
     */
    public function delete( $post_id ) {
        $post = $this->app->database()->fetch_by_id( $post_id );

        if ( is_wp_error( $post ) ) {
            /*　@var WP_Error $post */
            return $post;
        }

        $github_path = get_post_meta( $post_id, '_wghs_github_path', true );

        $message = apply_filters(
            'wghs_commit_msg_delete',
            sprintf(
                'Deleting %s via %s',
                $github_path,
                site_url()
            ),
            $post
        ) . $this->get_commit_msg_tag();

        $result = $this->app->api()->persist()->delete_file( $github_path, $post->sha(), $message );

        if ( is_wp_error( $result ) ) {
            /*　@var WP_Error $result */
            return $result;
        }

        return __( 'Export to GitHub completed successfully.', 'wordpress-github-sync' );
    }

    /**
     * Gets the commit message tag.
     *
     * @return string
     */
    protected function get_commit_msg_tag() {
        $tag = apply_filters( 'wghs_commit_msg_tag', 'wghs' );

        if ( ! $tag ) {
            throw new Exception( __( 'Commit message tag not set. Filter `wghs_commit_msg_tag` misconfigured.', 'wordpress-github-sync' ) );
        }

        return ' - ' . $tag;
    }
}
