<?php
/**
 * GitHub Import Manager
 *
 * @package Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Import
 */
class Wordpress_GitHub_Sync_Import {

    /**
     * Application container.
     *
     * @var Wordpress_GitHub_Sync
     */
    protected $app;

    /**
     * Initializes a new import manager.
     *
     * @param Wordpress_GitHub_Sync $app Application container.
     */
    public function __construct( Wordpress_GitHub_Sync $app ) {
        $this->app = $app;
    }

    /**
     * Imports a payload.
     * @param  Wordpress_GitHub_Sync_Payload $payload
     *
     * @return string|WP_Error
     */
    public function payload( Wordpress_GitHub_Sync_Payload $payload ) {

        $result = $this->app->api()->fetch()->compare( $payload->get_before_commit_id() );

        if ( is_wp_error( $result ) ) {
            /* @var WP_Error $result */
            return $result;
        }

        if ( is_array( $result ) ) {
            $result = $this->import_files( $result );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return __( 'Payload processed', 'wordpress-github-sync' );
    }

    /**
     * import blob by files
     * @param  Wordpress_GitHub_Sync_File_Info[] $files
     * @param  boolean $force
     *
     * @return true|WP_Error
     */
    protected function import_files( $files, $force = false ) {

        $error = true;

        // A product_variation links to its parent product via parent_slug, which
        // is resolved against the database at import time (see Database::post_args).
        // If a variation is imported before its parent product exists, the lookup
        // fails and the variation is saved parentless -- which then exports back as
        // an orphan file at the repo root. Guard against this two ways:
        //   1. Order the batch so parent products are imported before variations.
        //   2. Defer any variation whose parent still cannot be resolved and retry
        //      it after the first pass, once every parent in the batch has landed.
        $files = $this->sort_files_parents_first( $files );

        $deferred = array();

        // Parent product IDs that received variations in this batch. After all
        // variations are imported we must sync each variable parent so
        // WooCommerce registers its children; otherwise a freshly-imported
        // variable product has the `variable` type term and `_product_attributes`
        // but no synced children, and WooCommerce re-derives it as `simple` on
        // the next load (which then exports back to the repo as `simple`,
        // dropping the variations list). See sync_variable_parents().
        $variation_parent_ids = array();

        foreach ( $files as $file ) {
            if ( ! $this->importable_file( $file ) ) {
                continue;
            }

            $blob = $this->app->api()->fetch()->blob( $file );
            // network error ?
            if ( ! $blob instanceof Wordpress_GitHub_Sync_Blob ) {
                continue;
            }

            $is_remove = 'removed' == $file->status;

            $result = false;
            if ( $this->importable_raw_file( $blob ) ) {
                $result = $this->import_raw_file( $blob, $is_remove );
            } elseif ( $this->importable_post( $blob ) ) {
                // To prevent production errors, we don't remove posts via GitHub file deletion.
                if ( ! $is_remove ) {
                    // An explicit `deleted: true` marker in the frontmatter is the
                    // sanctioned way to remove a post -- it's opt-in and survives
                    // the file itself remaining in the repo.
                    if ( $this->is_delete_marker( $blob ) ) {
                        $result = $this->app->database()->delete_post( $blob );

                        // When a post was actually removed (a positive ID, not
                        // the 0 no-op), mirror a front-end delete by removing
                        // the source file from the repo too. An unresolved
                        // marker leaves the file in place.
                        if ( ! is_wp_error( $result ) && $result ) {
                            $result = $this->app->export()->delete_file( $blob->path() );
                        }
                    } elseif ( $this->is_variation_blob( $blob ) && ! $this->variation_parent_exists( $blob ) ) {
                        // Defer variations whose parent product is not yet present.
                        $deferred[] = $blob;
                        continue;
                    } else {
                        $result = $this->import_post( $blob, $force );

                        if ( ! is_wp_error( $result ) && $this->is_variation_blob( $blob ) ) {
                            $parent_id = $this->variation_parent_id( $blob );
                            if ( $parent_id ) {
                                $variation_parent_ids[ $parent_id ] = $parent_id;
                            }
                        }
                    }
                }
            }

            if ( is_wp_error( $result ) ) {
                /* @var WP_Error $result */
                $error = wghs_append_error( $error, $result );
            }
        }

        // Second pass: retry deferred variations now that all parents are imported.
        foreach ( $deferred as $blob ) {
            if ( ! $this->variation_parent_exists( $blob ) ) {
                error_log( sprintf( __( 'Parent product %s not found for variation %s after import; skipping to avoid creating an orphan.' ), $this->variation_parent_slug( $blob ), $blob->path() ) );
                continue;
            }

            $result = $this->import_post( $blob, $force );
            if ( is_wp_error( $result ) ) {
                /* @var WP_Error $result */
                $error = wghs_append_error( $error, $result );
            } else {
                $parent_id = $this->variation_parent_id( $blob );
                if ( $parent_id ) {
                    $variation_parent_ids[ $parent_id ] = $parent_id;
                }
            }
        }

        // Final pass: sync every variable parent that received variations so
        // WooCommerce links the children and preserves the `variable` type.
        $this->sync_variable_parents( $variation_parent_ids );

        return $error;
    }

    /**
     * Resolves the WordPress product ID for a variation blob's parent_slug.
     *
     * @param Wordpress_GitHub_Sync_Blob $blob
     *
     * @return int Parent product ID, or 0 if it cannot be resolved.
     */
    protected function variation_parent_id( Wordpress_GitHub_Sync_Blob $blob ) {
        $slug = $this->variation_parent_slug( $blob );
        if ( '' === $slug ) {
            return 0;
        }

        $parent = get_page_by_path( $slug, OBJECT, 'product' );

        return $parent ? (int) $parent->ID : 0;
    }

    /**
     * Reconciles variable parents whose variations were imported in this batch.
     *
     * The variable `product_type` term, `_product_attributes`, and the variation
     * posts (correctly parented) are all written during import -- so the data on
     * disk is correct. The problem is a stale object cache: when a parent product
     * is saved and then re-exported within the same request, the parent's product
     * object was cached as `simple` (built before its variations existed), and WC
     * never invalidates it. The export then reads that stale `simple` object and
     * writes `product_type: simple` back to the repo, dropping the variations.
     *
     * (A plain WC_Product_Variable::sync() does not help: it loads the product
     * via wc_get_product(), gets the cached `simple` object, and bails -- the
     * cache must be busted first so it loads a variable object.)
     *
     * For each affected parent we therefore: bust the post and product caches so
     * the next read resolves the correct `variable` class, run sync() to rebuild
     * the children/price cache, then re-export the parent so the corrected
     * `variable` frontmatter (with its variations list) reaches the repository.
     *
     * @param array $parent_ids ID-keyed map of parent product IDs.
     */
    protected function sync_variable_parents( $parent_ids ) {
        if ( empty( $parent_ids ) || ! class_exists( 'WC_Product_Variable' ) ) {
            return;
        }

        foreach ( $parent_ids as $parent_id ) {
            // Bust the stale `simple` object that was cached before the
            // variations were imported, so the next load resolves `variable`.
            clean_post_cache( $parent_id );
            wp_cache_delete( $parent_id, 'posts' );
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $parent_id );
            }

            // Now sync() loads a fresh variable object and rebuilds its
            // children list and price cache.
            WC_Product_Variable::sync( $parent_id );

            // Re-export the parent so the repository receives the corrected
            // `variable` frontmatter; without this the flattened `simple`
            // export written earlier in the request remains on GitHub.
            $post = new Wordpress_GitHub_Sync_Post( $parent_id, $this->app->api() );

            // The parent already has a GitHub path from its earlier export this
            // request. Seed old_github_path from that stored meta so export_post
            // takes the update branch (and skips if unchanged) rather than
            // attempting to create a duplicate file.
            $existing_path = get_post_meta( $parent_id, '_wghs_github_path', true );
            if ( $existing_path ) {
                $post->set_old_github_path( $existing_path );
            }

            $result = $this->app->export()->export_post( $post );
            if ( is_wp_error( $result ) ) {
                error_log( sprintf( __( 'Failed to re-export variable parent %d after sync: %s' ), $parent_id, $result->get_error_message() ) );
            }
        }
    }

    /**
     * Orders files so parent products are imported before product variations.
     *
     * Sorts by path depth (shallower first), so products/<slug>.md precedes
     * products/<slug>/variations/<child>.md. Stable for everything else.
     *
     * @param array $files
     *
     * @return array
     */
    protected function sort_files_parents_first( $files ) {
        $indexed = array();
        foreach ( $files as $i => $file ) {
            $indexed[] = array( $i, $file );
        }

        usort( $indexed, function ( $a, $b ) {
            $depth_a = substr_count( $a[1]->path, '/' );
            $depth_b = substr_count( $b[1]->path, '/' );
            if ( $depth_a !== $depth_b ) {
                return $depth_a - $depth_b;
            }
            // Preserve original order for equal depth (stable sort).
            return $a[0] - $b[0];
        } );

        $sorted = array();
        foreach ( $indexed as $pair ) {
            $sorted[] = $pair[1];
        }

        return $sorted;
    }

    /**
     * Whether a blob requests deletion of its WordPress post.
     *
     * A truthy `deleted` field in the frontmatter (true, "true", "yes", 1)
     * marks the post for removal instead of import.
     *
     * @param Wordpress_GitHub_Sync_Blob $blob
     *
     * @return bool
     */
    protected function is_delete_marker( Wordpress_GitHub_Sync_Blob $blob ) {
        $meta = $blob->meta();

        if ( ! array_key_exists( 'deleted', $meta ) ) {
            return false;
        }

        $deleted = $meta['deleted'];

        if ( is_bool( $deleted ) ) {
            return $deleted;
        }

        return in_array(
            strtolower( (string) $deleted ),
            array( 'true', 'yes', '1' ),
            true
        );
    }

    /**
     * Whether a blob represents a product_variation with a declared parent.
     *
     * @param Wordpress_GitHub_Sync_Blob $blob
     *
     * @return bool
     */
    protected function is_variation_blob( Wordpress_GitHub_Sync_Blob $blob ) {
        $meta = $blob->meta();
        return ! empty( $meta['layout'] )
            && 'product_variation' === $meta['layout']
            && ! empty( $meta['parent_slug'] );
    }

    /**
     * Returns the parent_slug declared by a variation blob, or ''.
     *
     * @param Wordpress_GitHub_Sync_Blob $blob
     *
     * @return string
     */
    protected function variation_parent_slug( Wordpress_GitHub_Sync_Blob $blob ) {
        $meta = $blob->meta();
        return ! empty( $meta['parent_slug'] ) ? $meta['parent_slug'] : '';
    }

    /**
     * Whether the parent product for a variation blob already exists in WordPress.
     *
     * @param Wordpress_GitHub_Sync_Blob $blob
     *
     * @return bool
     */
    protected function variation_parent_exists( Wordpress_GitHub_Sync_Blob $blob ) {
        $slug = $this->variation_parent_slug( $blob );
        if ( '' === $slug ) {
            return false;
        }
        return (bool) get_page_by_path( $slug, OBJECT, 'product' );
    }

    /**
     * Imports the latest commit on the master branch.
     *
     * @param  boolean $force
     * @return string|WP_Error
     */
    public function master( $force = false ) {
        $result = $this->app->api()->fetch()->tree_recursive();

        if ( is_wp_error( $result ) ) {
            WP_CLI::debug('Error detected on tree_recursive!');
            /* @var WP_Error $result */
            return $result;
        }


        if ( is_array( $result ) ) {
            $result = $this->import_files( $result, $force );
        }

        if ( is_wp_error( $result ) ) {
            /* @var WP_Error $result */
            return $result;
        }

        return __( 'Payload processed', 'wordpress-github-sync' );
    }

    /**
     * Checks whether the provided blob should be imported.
     *
     * @param Wordpress_GitHub_Sync_File_Info $file
     *
     * @return bool
     */
    protected function importable_file( Wordpress_GitHub_Sync_File_Info $file ) {

        $path = $file->path;
        $prefixs = array( 'pages/', 'posts/', 'courses/', 'fieldatlas/', 'glossary/', 'newsletters/', 'products/');
        foreach ($prefixs as $prefix) {
            if ( ! strncasecmp($path, $prefix, strlen( $prefix ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks whether the provided blob should be imported.
     *
     * @param Wordpress_GitHub_Sync_Blob $blob Blob to validate.
     *
     * @return bool
     */
    protected function importable_post( Wordpress_GitHub_Sync_Blob $blob ) {
        // global $wpdb;

        // // Skip the repo's readme.
        // if ( 'readme' === strtolower( substr( $blob->path(), 0, 6 ) ) ) {
        //  return false;
        // }

        // // If the blob sha already matches a post, then move on.
        // if ( ! is_wp_error( $this->app->database()->fetch_by_sha( $blob->sha() ) ) ) {
        //  return false;
        // }

        if ( ! $blob->has_frontmatter() ) {
            return false;
        }

        return true;
    }

    /**
     * Imports a post into wordpress
     * @param  Wordpress_GitHub_Sync_Blob $blob
     * @param  boolean                $force
     * @return WP_Error|bool
     */
    protected function import_post( Wordpress_GitHub_Sync_Blob $blob, $force = false ) {
        $post = $this->blob_to_post( $blob, $force );

        if ( ! $post instanceof Wordpress_GitHub_Sync_Post ) {
            return false;
        }

        $result = $this->app->database()->save_post( $post );
        if ( is_wp_error( $result ) ) {
            /** @var WP_Error $result */
            return $result;
        }

        if ( $post->is_new() ||
                ! wghs_equal_path( $post, $blob ) ||
                ! wghs_equal_front_matter( $post, $blob ) ) {

            $result = $this->app->export()->export_post( $post );

            if ( is_wp_error( $result ) ) {
                /** @var WP_Error $result */
                return $result;
            }
        }

        clean_post_cache( $post->id() );

        return true;
    }

    /**
     * import raw file
     * @param  Wordpress_GitHub_Sync_Blob $blob
     * @return bool
     */
    protected function importable_raw_file( Wordpress_GitHub_Sync_Blob $blob ) {
        if ( $blob->has_frontmatter() ) {
            return false;
        }

        return true;
    }

    /**
     * Imports a raw file content into file system.
     * @param  Wordpress_GitHub_Sync_Blob $blob
     * @param  bool                   $is_remove
     */
    protected function import_raw_file( Wordpress_GitHub_Sync_Blob $blob, $is_remove ) {
        $arr = wp_upload_dir();
        $path = $arr['basedir'] . '/wordpress-github-sync/' . $blob->path();
        if ( $is_remove ) {
            if ( file_exists($path) ) {
                unlink($path);
            }
        } else {
            $dirname = dirname($path);
            if ( ! file_exists($dirname) ) {
                wp_mkdir_p($dirname);
            }

            file_put_contents($path, $blob->content());
        }
        return true;
    }

    /**
     * Imports a single blob content into matching post.
     *
     * @param Wordpress_GitHub_Sync_Blob $blob Blob to transform into a Post.
     * @param boolean                $force
     *
     * @return Wordpress_GitHub_Sync_Post|false
     */
    protected function blob_to_post( Wordpress_GitHub_Sync_Blob $blob, $force = false ) {
        $args = array( 'post_content' => $blob->content_import() );
        $meta = $blob->meta();

        $id = false;

        if ( ! empty( $meta ) ) {
            if ( array_key_exists( 'layout', $meta ) ) {
                $args['post_type'] = $meta['layout'];
                unset( $meta['layout'] );
            }

            if ( array_key_exists( 'published', $meta ) ) {
                $args['post_status'] = true === $meta['published'] ? 'publish' : 'draft';
                unset( $meta['published'] );
            }

            if ( array_key_exists( 'post_title', $meta ) ) {
                $args['post_title'] = $meta['post_title'];
                unset( $meta['post_title'] );
            }

            if ( array_key_exists( 'post_name', $meta ) ) {
                $args['post_name'] = $meta['post_name'];
                unset( $meta['post_name'] );
            }

            if ( array_key_exists( 'ID', $meta ) ) {
                $id = $args['ID'] = $meta['ID'];
                $blob->set_id( $id );
                unset( $meta['ID'] );
            }

            if ( array_key_exists( 'post_date', $meta ) ) {
                if ( empty( $meta['post_date'] ) ) {
                    $meta['post_date'] = current_time( 'mysql' );
                }

                $args['post_date'] = $meta['post_date'];
                unset( $meta['post_date'] );
            }

            if ( array_key_exists( 'post_excerpt', $meta ) ) {
                $args['post_excerpt'] = $meta['post_excerpt'];
                unset( $meta['post_excerpt'] );
            }
        }

        $meta['_wghs_sha'] = $blob->sha();

        if ( ! $force && $id ) {
            $old_sha = get_post_meta( $id, '_wghs_sha', true );
            $old_github_path = get_post_meta( $id, '_wghs_github_path', true );

            // dont save post when has same sha
            if ( $old_sha  && $old_sha == $meta['_wghs_sha'] &&
                 $old_github_path && $old_github_path == $blob->path() ) {
                return false;
            }
        }

        $post = new Wordpress_GitHub_Sync_Post( $args, $this->app->api() );
        $post->set_old_github_path( $blob->path() );
        $post->set_meta( $meta );
        $post->set_blob( $blob );
        $blob->set_id( $post->id() );

        return $post;
    }
}
