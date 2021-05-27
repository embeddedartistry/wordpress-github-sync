<?php


/**
 * Append error
 * @param  mixed|WP_Error $error
 * @param  WP_Error   $error2
 * @return WP_Error
 */
function wghs_append_error( $error, $error2 ) {
    if ( is_wp_error( $error ) ) {
        $error->add( $error2->get_error_code(), $error2->get_error_message() );
    }
    return $error2;
}

/**
 * Test is equal front matter of post and blob
 * @param  Wordpress_GitHub_Sync_Post $post
 * @param  Wordpress_GitHub_Sync_Blob $blob
 * @return bool
 */
function wghs_equal_front_matter( $post, $blob ) {
    $str1 = $post->front_matter();
    $str2 = $blob->front_matter();
    return trim($str1) === trim($str2);
}

function wghs_equal_path( $post, $blob ) {
    $str1 = $post->github_path();
    $str2 = $blob->path();
    return trim($str1) === trim($str2);
}

/**
 * Check is dont export wordpress content
 * @return bool
 */
function wghs_is_dont_export_content() {
    return 'yes' === get_option( 'wghs_dont_export_content' );
}

/**
 * Calc git sha
 * https://git-scm.com/book/en/v2/Git-Internals-Git-Objects#_object_storage
 * @param  string $content
 * @return string
 */
function wghs_git_sha( $content ) {
    // $header = "blob $len\0"
    // sha1($header . $content)
    $len = strlen( $content );
    return sha1( "blob $len\0$content" );
}
