<?php
/**
 * Fetch API client class.
 * @package Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Fetch_Client
 */
class Wordpress_GitHub_Sync_Fetch_Client extends Wordpress_GitHub_Sync_Base_Client {

    /**
     * Compare a commit by sha with master from the GitHub API
     *
     * @param string $sha Sha for commit to retrieve.
     *
     * @return Wordpress_GitHub_Sync_File_Info[]|WP_Error
     */
    public function compare( $sha ) {
        // https://api.github.com/repos/litefeel/testwpsync/compare/861f87e8851b8debb78db548269d29f8da4d94ac...master
        $endpoint = $this->compare_endpoint();
        $branch = $this->branch();
        $data = $this->call( 'GET', "$endpoint/$sha...$branch" );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $files = array();
        foreach ($data->files as $file) {
            $file->path = $file->filename;
            $files[] = new Wordpress_GitHub_Sync_File_Info($file);
        }

        return $files;
    }

    /**
     * Calls the content API to get the post's contents and metadata
     *
     * Returns Object the response from the API
     *
     * @param Wordpress_GitHub_Sync_Post $post Post to retrieve remote contents for.
     *
     * @return mixed
     */
    public function remote_contents( $post ) {
        return $this->call( 'GET', $this->content_endpoint( $post->github_path() ) );
    }



    public function exists( $path ) {
        $result = $this->call( 'GET', $this->content_endpoint( $path ) );
        if ( is_wp_error( $result ) ) {
            return false;
        }
        return true;
    }

    /**
     * Retrieves a tree by sha recursively from the GitHub API
     *
     * @param string $sha Commit sha to retrieve tree from.
     *
     * @return Wordpress_GitHub_Sync_File_Info[]|WP_Error
     */
    public function tree_recursive( $sha = '_default' ) {

        if ( '_default' === $sha ) {
            $sha = $this->branch();
        }

        $data = $this->call( 'GET', $this->tree_endpoint() . '/' . $sha . '?recursive=1' );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $files = array();

        foreach ( $data->tree as $index => $thing ) {
            // We need to remove the trees because
            // the recursive tree includes both
            // the subtrees as well the subtrees' blobs.
            if ( 'blob' === $thing->type ) {
                $thing->status = '';
                $files[] = new Wordpress_GitHub_Sync_File_Info( $thing );
            }
        }

        return $files;
    }

    /**
     * Retrieves the blob data for a given sha
     *
     * @param Wordpress_GitHub_Sync_File_Info $fileinfo
     *
     * @return Wordpress_GitHub_Sync_Blob|WP_Error
     */
    public function blob( Wordpress_GitHub_Sync_File_Info $fileinfo ) {
        $data = $this->call( 'GET', $this->blob_endpoint() . '/' . $fileinfo->sha );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $data->path = $fileinfo->path;
        return new Wordpress_GitHub_Sync_Blob( $data );
    }

    /**
     * Get blob by path
     * @param  string $path
     * @return Wordpress_GitHub_Sync_Blob|WP_Error
     */
    public function blob_by_path( $path ) {
        $result = $this->call( 'GET', $this->content_endpoint( $path ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new Wordpress_GitHub_Sync_Blob( $result );
    }
}
