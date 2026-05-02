<?php
/**
 * The post object which represents both the GitHub and WordPress post
 * @package WP_Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Post
 */
class Wordpress_GitHub_Sync_Post {

    /**
     * Api object
     *
     * @var Wordpress_GitHub_Sync_Api
     */
    public $api;

    /**
     * Post ID
     * @var integer
     */
    public $id = 0;

    /**
     * Blob object
     * @var Wordpress_GitHub_Sync_Blob
     */
    public $blob;

    /**
     * Post object
     * @var WP_Post
     */
    public $post;

    /**
     * Post args.
     *
     * @var array
     */
    protected $args;

    /**
     * Post meta.
     *
     * @var array
     */
    protected $meta;

    /**
     * Whether the post has been saved.
     *
     * @var bool
     */
    protected $new = true;


    protected $old_github_path;

    /**
     * Instantiates a new Post object
     *
     * @param int|array                 $id_or_args Either a post ID or an array of arguments.
     * @param Wordpress_GitHub_Sync_Api $api API object.
     *
     * @todo remove database operations from this method
     */
    public function __construct( $id_or_args, Wordpress_GitHub_Sync_Api $api ) {
        $this->api = $api;

        if ( is_numeric( $id_or_args ) ) {
            $this->id   = (int) $id_or_args;
            $this->post = get_post( $this->id );
            $this->new  = false;
        }

        if ( is_array( $id_or_args ) ) {
            $this->args = $id_or_args;

            if ( isset( $this->args['ID'] ) ) {
                $this->post = get_post( $this->args['ID'] );

                if ( $this->post ) {
                    $this->id  = $this->post->ID;
                    $this->new = false;
                } else {
                    unset( $this->args['ID'] );
                }
            }
        }
    }

    public function id() {
        return $this->id;
    }

    /**
     * Returns the post type
     */
    public function type() {
        return $this->post->post_type;
    }

    /**
     * Returns the post type
     */
    public function status() {
        return $this->post->post_status;
    }

    /**
     * Returns the post name
     */
    public function name() {
        return $this->post->post_name;
    }

    /**
     * Returns true if the post has a password
     * @return bool
     */
    public function has_password() {
        return ! empty( $this->post->post_password );
    }

    /**
     * Combines the 2 content parts for GitHub
     */
    public function github_content() {
        $use_blob = wghs_is_dont_export_content() && $this->blob;
        $content = $use_blob ?
            $this->blob->post_content() :
            $this->post_content();

        return $this->front_matter() . $content;
    }

    /**
     * The post's YAML frontmatter
     *
     * Returns String the YAML frontmatter, ready to be written to the file
     */
    public function front_matter() {
        return "---\n" . spyc_dump( $this->meta() ) . "---\n";
    }

    /**
     * Returns the post_content
     *
     * Markdownify's the content if applicable
     */
    public function post_content() {
        $content = $this->post->post_content;

        if ( function_exists( 'wpmarkdown_html_to_markdown' ) ) {
            $content = wpmarkdown_html_to_markdown( $content );
        } else if ( class_exists( 'WPCom_Markdown' ) ) {
            if ( WPCom_Markdown::get_instance()->is_markdown( $this->post->ID ) ) {
                $content = $this->post->post_content_filtered;
            }
        }

        return apply_filters( 'wghs_content_export', $content, $this );
    }

    public function old_github_path() {
        return $this->old_github_path;
    }

    public function set_old_github_path( $path ) {
        $this->old_github_path = $path;
        update_post_meta( $this->id, '_wghs_github_path', $path );
    }


    /**
     * Retrieves or calculates the proper GitHub path for a given post
     *
     * Returns (string) the path relative to repo root
     */
    public function github_path() {
        $path = $this->github_directory() . $this->github_filename();
        //error_log(sprintf(__('github directory: %s, github_filename: %s'), $this->github_directory(), $this->github_filename()));

        return $path;
    }

    /**
     * Get GitHub directory based on post
     *
     * @return string
     */
    public function github_directory() {

        $name = '';

        switch ( $this->type() ) {
            case 'post':
                $name = 'posts/' . get_the_time( 'Y', $this->id );
                break;
            case 'page':
                $name = 'pages';
                break;
            case 'glossary':
                $name = 'glossary';
                break;
            case 'fieldatlas':
            	$name = 'fieldatlas';
            	break;
            case 'newsletters':
                $name = 'newsletters/' . get_the_time( 'Y', $this->id );
            	break;
            case 'course':
            	// Course .md files are placed into the a subdirectory directory
            	// corresponding to the course name. Lessons/modules will also go here.
            	// We use get_the_title because get_name() can return different
            	// values than what is used for the lesson in some cases (e.g.,
            	// course was renamed), which causes two directories to appear
            	// for each course
            	$name = 'courses/' . sanitize_title(get_the_title($this->id));
            	break;
            case 'lesson':
				// Lessons need to be organized under courses/course_name/module_name/
                $course_id = Sensei()->lesson->get_course_id($this->id);
                $course_name = get_the_title($course_id);
				$name = 'courses/' . sanitize_title($course_name);
				$module = Sensei()->modules->get_lesson_module($this->id);
				if($module)
                {
                    // We want to remove author names from module names
                    // because Sensei is putting those in place for admin users
                    // which causes problems when importing or exporting post changes
                    $exploded_module_name = explode('(', $module->name);
                    $name = $name . '/' . sanitize_title($exploded_module_name[0]);
                }
                else
				{
                    error_log(sprintf(__('Module for lesson %s could not be grabbed!'), $this->get_name()));
				}

				break;
            case 'product':
                $name = 'products';
                break;
            case 'product_variation':
                $parent_id = $this->post->post_parent;
                if ( $parent_id ) {
                    $parent = get_post( $parent_id );
                    if ( $parent ) {
                        $parent_slug = $parent->post_name ? $parent->post_name : sanitize_title( get_the_title( $parent ) );
                        $name = 'products/' . $parent_slug . '/variations';
                    } else {
                        error_log( sprintf( __( 'Parent product %d not found for variation %d' ), $parent_id, $this->id ) );
                    }
                } else {
                    error_log( sprintf( __( 'Variation %d has no parent product' ), $this->id ) );
                }
                break;
            default:
                $obj = get_post_type_object( $this->type() );

                if ( $obj ) {
                    $name = strtolower( $obj->labels->name );
                }

                if ( ! $name ) {
                    $name = '';
                }
        }

        if ( $name ) {
            $name = $name . '/';
        }

        return apply_filters( 'wghs_directory_published', $name, $this );
    }

    /**
     * Build GitHub filename
     */
    public function github_filename() {
        $filename = $this->get_name() . '.md';

        return apply_filters( 'wghs_filename', $filename, $this );
    }

    /**
     * Returns a post slug we can use in the GitHub filename
     *
     * @return string
     */
    protected function get_name() {
        if ( '' !== $this->name() ) {
            return $this->name();
        }

        return sanitize_title( get_the_title( $this->post ) );
    }

    /**
     * is put on github
     * @return boolean
     */
    public function is_on_github() {
        $sha = get_post_meta( $this->id, '_wghs_sha', true );
        $github_path = get_post_meta( $this->id, '_wghs_github_path', true );
        if ( $sha && $github_path ) {
            return true;
        }
        return false;
    }

    /**
     * Returns the URL for the post on GitHub.
     *
     * @return string
     */
    public function github_view_url() {
        $github_path = get_post_meta( $this->id, '_wghs_github_path', true );
        $repository = $this->api->fetch()->repository();
        $branch = $this->api->fetch()->branch();

        return "https://github.com/$repository/blob/$branch/$github_path";
    }

    /**
     * Returns the URL for the post on GitHub.
     *
     * @return string
     */
    public function github_edit_url() {
        $github_path = get_post_meta( $this->id, '_wghs_github_path', true );
        $repository = $this->api->fetch()->repository();
        $branch = $this->api->fetch()->branch();

        return "https://github.com/$repository/edit/$branch/$github_path";
    }

    /**
     * Determines the last author to modify the post
     *
     * Returns Array an array containing the author name and email
     */
    public function last_modified_author() {
        if ( $last_id = get_post_meta( $this->id, '_edit_last', true ) ) {
            $user = get_userdata( $last_id );

            if ( $user ) {
                return array( 'name' => $user->display_name, 'email' => $user->user_email );
            }
        }

        return array();
    }

    /**
     * The post's sha
     * Cached as post meta, or will make a live call if need be
     *
     * Returns String the sha1 hash
     */
    public function sha() {
        $sha = get_post_meta( $this->id, '_wghs_sha', true );

        // If we've done a full export and we have no sha
        // then we should try a live check to see if it exists.
        // if ( ! $sha && 'yes' === get_option( '_wghs_fully_exported' ) ) {

        //  // @todo could we eliminate this by calling down the full tree and searching it
        //  $data = $this->api->fetch()->remote_contents( $this );

        //  if ( ! is_wp_error( $data ) ) {
        //      update_post_meta( $this->id, '_wghs_sha', $data->sha );
        //      $sha = $data->sha;
        //  }
        // }

        // if the sha still doesn't exist, then it's empty
        if ( ! $sha || is_wp_error( $sha ) ) {
            $sha = '';
        }

        return $sha;
    }

    /**
     * Save the sha to post
     *
     * @param string $sha
     */
    public function set_sha( $sha ) {
        update_post_meta( $this->id, '_wghs_sha', $sha );
    }

    /**
     * The post's metadata
     *
     * Returns Array the post's metadata
     */
    public function meta() {
        $meta = array(
            'ID'           => $this->id,
            'post_title'   => get_the_title( $this->post ),
            'post_name'    => $this->post->post_name,
            'author'       => ( $author = get_userdata( $this->post->post_author ) ) ? $author->display_name : '',
            'post_date'    => $this->post->post_date,
            'post_excerpt' => $this->post->post_excerpt,
            'layout'       => get_post_type( $this->post ),
            'link'         => get_permalink( $this->post ),
            'published'    => 'publish' === $this->status() ? true : false,
            'tags'         => wp_get_post_tags( $this->id, array( 'fields' => 'names' ) ),
            'categories'   => wp_get_post_categories( $this->id, array( 'fields' => 'names' ) )
        );
        if ( empty($this->post->post_name) ) {
            unset($meta['post_name']);
        }
        if ( empty($this->post->post_excerpt) ) {
            unset($meta['post_excerpt']);
        }
        if ( 'yes' == get_option('wghs_ignore_author') ) {
            unset($meta['author']);
        }

        // Glossary-specific metadata (CM Tooltip Glossary plugin)
        if ( 'glossary' === get_post_type( $this->post ) ) {
            $synonyms = get_post_meta( $this->id, 'cmtt_synonyms', true );
            if ( ! empty( $synonyms ) ) {
                $meta['cmtt_synonyms'] = $synonyms;
            }
            $variations = get_post_meta( $this->id, 'cmtt_variations', true );
            if ( ! empty( $variations ) ) {
                $meta['cmtt_variations'] = $variations;
            }
            $abbreviations = get_post_meta( $this->id, 'cmtt_abbreviations', true );
            if ( ! empty( $abbreviations ) ) {
                $meta['cmtt_abbreviations'] = $abbreviations;
            }
        }

        // Lesson-specific metadata (Sensei LMS)
        if ( 'lesson' === get_post_type( $this->post ) ) {
            $course_id = Sensei()->lesson->get_course_id( $this->id );
            $course_name = get_the_title( $course_id );
            if ( ! empty( $course_name ) ) {
                $meta['course'] = $course_name;
            }

            $module = Sensei()->modules->get_lesson_module( $this->id );
            if ( $module ) {
                $exploded_module_name = explode( '(', $module->name );
                $meta['module'] = trim( $exploded_module_name[0] );
            }
        }

        // Product-specific metadata (WooCommerce)
        $post_type = get_post_type( $this->post );
        if ( ( 'product' === $post_type || 'product_variation' === $post_type ) && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $this->id );
            if ( $product ) {
                $meta = array_merge( $meta, $this->product_meta( $product ) );
            }
        }

        //convert traditional post_meta values, hide hidden values, skip already populated values
        // foreach ( get_post_custom( $this->id ) as $key => $value ) {

        //  if ( '_' === substr( $key, 0, 1 ) || isset( $meta[ $key ] ) ) {
        //      continue;
        //  }

        //  $meta[ $key ] = $value;

        // }

        return apply_filters( 'wghs_post_meta', $meta, $this );
    }

    /**
     * Builds WooCommerce-specific frontmatter for a product or product_variation.
     *
     * @param WC_Product $product
     *
     * @return array
     */
    protected function product_meta( $product ) {
        $is_variation = $product->is_type( 'variation' );
        $meta = array();

        if ( $is_variation ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) {
                $parent = get_post( $parent_id );
                if ( $parent ) {
                    $meta['parent_slug'] = $parent->post_name ? $parent->post_name : sanitize_title( get_the_title( $parent ) );
                }
            }

            $attribute_values = array();
            foreach ( $product->get_attributes() as $slug => $value ) {
                if ( '' !== $value && null !== $value ) {
                    $attribute_values[ $slug ] = $value;
                }
            }
            if ( ! empty( $attribute_values ) ) {
                $meta['attribute_values'] = $attribute_values;
            }
        } else {
            $meta['product_type'] = $product->get_type();
        }

        // Identity
        $sku = $product->get_sku();
        if ( '' !== $sku ) {
            $meta['sku'] = $sku;
        }
        if ( ! $is_variation ) {
            $meta['featured'] = $product->get_featured();
            $meta['catalog_visibility'] = $product->get_catalog_visibility();
        }

        // Pricing (preserve as strings; WC stores as strings)
        $regular_price = $product->get_regular_price( 'edit' );
        if ( '' !== $regular_price && null !== $regular_price ) {
            $meta['regular_price'] = (string) $regular_price;
        }
        $sale_price = $product->get_sale_price( 'edit' );
        if ( '' !== $sale_price && null !== $sale_price ) {
            $meta['sale_price'] = (string) $sale_price;
        }
        $sale_from = $product->get_date_on_sale_from( 'edit' );
        if ( $sale_from ) {
            $meta['sale_price_dates_from'] = $sale_from->date( 'Y-m-d H:i:s' );
        }
        $sale_to = $product->get_date_on_sale_to( 'edit' );
        if ( $sale_to ) {
            $meta['sale_price_dates_to'] = $sale_to->date( 'Y-m-d H:i:s' );
        }
        if ( ! $is_variation ) {
            $meta['tax_status'] = $product->get_tax_status();
            $tax_class = $product->get_tax_class();
            if ( '' !== $tax_class ) {
                $meta['tax_class'] = $tax_class;
            }
        }

        // Inventory configuration (NOT runtime stock counts)
        $meta['manage_stock'] = (bool) $product->get_manage_stock();
        $meta['stock_status'] = $product->get_stock_status();
        $backorders = $product->get_backorders();
        if ( '' !== $backorders ) {
            $meta['backorders'] = $backorders;
        }
        if ( ! $is_variation ) {
            $meta['sold_individually'] = (bool) $product->get_sold_individually();
        }

        // Shipping
        foreach ( array( 'weight', 'length', 'width', 'height' ) as $dim ) {
            $getter = 'get_' . $dim;
            $value = $product->$getter();
            if ( '' !== $value && null !== $value ) {
                $meta[ $dim ] = (string) $value;
            }
        }
        if ( ! $is_variation ) {
            $shipping_class_id = $product->get_shipping_class_id();
            if ( $shipping_class_id ) {
                $term = get_term( $shipping_class_id, 'product_shipping_class' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $meta['shipping_class'] = $term->slug;
                }
            }
        }

        // Type flags
        $meta['virtual'] = (bool) $product->get_virtual();
        $meta['downloadable'] = (bool) $product->get_downloadable();

        // External-product fields
        if ( ! $is_variation && $product->is_type( 'external' ) ) {
            $product_url = $product->get_product_url();
            if ( '' !== $product_url ) {
                $meta['product_url'] = $product_url;
            }
            $button_text = $product->get_button_text();
            if ( '' !== $button_text ) {
                $meta['button_text'] = $button_text;
            }
        }

        if ( ! $is_variation ) {
            // Taxonomies
            $cats = wp_get_post_terms( $this->id, 'product_cat', array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
                $meta['product_categories'] = $cats;
            }
            $tags = wp_get_post_terms( $this->id, 'product_tag', array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
                $meta['product_tags'] = $tags;
            }

            // Attributes
            $attributes = $this->serialize_product_attributes( $product );
            if ( ! empty( $attributes ) ) {
                $meta['attributes'] = $attributes;
            }

            // Variable-product extras
            if ( $product->is_type( 'variable' ) ) {
                $defaults = $product->get_default_attributes();
                if ( ! empty( $defaults ) ) {
                    $meta['default_attributes'] = $defaults;
                }
                $variations = array();
                foreach ( $product->get_children() as $child_id ) {
                    $child = get_post( $child_id );
                    if ( $child ) {
                        $variations[] = $child->post_name ? $child->post_name : sanitize_title( get_the_title( $child ) );
                    }
                }
                if ( ! empty( $variations ) ) {
                    $meta['variations'] = $variations;
                }
            }

            // Images: store attachment IDs. Resolve to URLs via media-index.json.
            $thumb_id = get_post_thumbnail_id( $this->id );
            if ( $thumb_id ) {
                $meta['featured_image_id'] = (int) $thumb_id;
            }
            $gallery_ids = $product->get_gallery_image_ids();
            if ( ! empty( $gallery_ids ) ) {
                $meta['gallery_image_ids'] = array_map( 'intval', $gallery_ids );
            }

            // Misc
            $purchase_note = $product->get_purchase_note();
            if ( '' !== $purchase_note ) {
                $meta['purchase_note'] = $purchase_note;
            }
        }

        return $meta;
    }

    /**
     * Renders _product_attributes into a YAML-friendly nested array.
     *
     * @param WC_Product $product
     *
     * @return array
     */
    protected function serialize_product_attributes( $product ) {
        $out = array();
        $raw = get_post_meta( $this->id, '_product_attributes', true );
        if ( ! is_array( $raw ) ) {
            return $out;
        }

        foreach ( $raw as $slug => $attr ) {
            $is_taxonomy = ! empty( $attr['is_taxonomy'] );
            $entry = array(
                'taxonomy' => (bool) $is_taxonomy,
                'label'    => $is_taxonomy ? wc_attribute_label( $attr['name'] ) : $attr['name'],
                'visible'  => ! empty( $attr['is_visible'] ),
                'variation'=> ! empty( $attr['is_variation'] ),
                'position' => isset( $attr['position'] ) ? (int) $attr['position'] : 0,
            );

            if ( $is_taxonomy ) {
                $terms = wp_get_post_terms( $this->id, $attr['name'], array( 'fields' => 'names' ) );
                $entry['terms'] = is_wp_error( $terms ) ? array() : $terms;
            } else {
                $value = isset( $attr['value'] ) ? $attr['value'] : '';
                $values = array_map( 'trim', explode( '|', $value ) );
                $values = array_values( array_filter( $values, 'strlen' ) );
                $entry['values'] = $values;
            }

            $out[ $slug ] = $entry;
        }

        return $out;
    }

    /**
     * Returns whether the Post has been saved in the DB yet.
     *
     * @return bool
     */
    public function is_new() {
        return $this->new;
    }

    /**
     * Sets the Post's meta.
     *
     * @param array $meta
     */
    public function set_meta( $meta ) {
        $this->meta = $meta;
    }

    /**
     * Returns the Post's arguments.
     *
     * @return array
     */
    public function get_args() {
        return $this->args;
    }

    /**
     * Returns the Post's meta.
     *
     * @return array
     */
    public function get_meta() {
        return $this->meta;
    }

    /**
     * Get the blob
     * @return Wordpress_GitHub_Sync_Blob
     */
    public function get_blob() {
        return $this->blob;
    }

    /**
     * Set the blob
     * @param Wordpress_GitHub_Sync_Blob $blob
     */
    public function set_blob( Wordpress_GitHub_Sync_Blob $blob ) {
        $this->blob = $blob;
    }

    /**
     * Sets the Post's WP_Post object.
     *
     * @param WP_Post $post
     *
     * @return $this
     */
    public function set_post( WP_Post $post ) {
        $this->post = $post;
        $this->id   = $post->ID;

        return $this;
    }

    /**
     * Transforms the Post into a Blob.
     *
     * @return Wordpress_GitHub_Sync_Blob
     */
    public function to_blob() {
        $data = new stdClass;

        $data->path    = $this->github_path();
        $data->content = $this->github_content();
        $data->sha     = $this->sha();

        return new Wordpress_GitHub_Sync_Blob( $data );
    }
}
