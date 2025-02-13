<?php
/**
 * GitHub Webhook payload.
 * @package Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Payload
 */
class Wordpress_GitHub_Sync_Payload {

    /**
     * Application container.
     *
     * @var Wordpress_GitHub_Sync
     */
    protected $app;

    /**
     * Payload data.
     *
     * @var stdClass
     */
    protected $data;

    /**
     * Wordpress_GitHub_Sync_Payload constructor.
     *
     * @param Wordpress_GitHub_Sync $app      Application container.
     * @param string                $raw_data Raw request data.
     */
    public function __construct( Wordpress_GitHub_Sync $app, $raw_data ) {
        $this->app  = $app;
        $this->data = json_decode( $raw_data );
    }

    /**
     * Returns whether payload should be imported.
     *
     * @return true|WP_Error
     */
    public function should_import() {
        // @todo how do we get this without importing the whole api object just for this?
        if ( strtolower( $this->data->repository->full_name ) !== strtolower( $this->app->api()->fetch()->repository() ) ) {
            return new WP_Error(
                'incorrect_repository',
                sprintf( 'Incorrect repository, %s -> %s .',
                    $this->data->repository->full_name,
                    $this->app->api()->fetch()->repository()
                )
            );
        }

        // The last term in the ref is the payload_branch name.
        $refs   = explode( '/', $this->data->ref );
        $payload_branch = array_pop( $refs );

        $branch = $this->app->api()->fetch()->branch();

        if ( $branch !== $payload_branch ) {
            return new WP_Error(
                'incorrect_branch',
                sprintf( 'Incorrect branch, %s -> %s .',
                    $payload_branch,
                    $branch
                )
            );
        }

        // We add a tag to commits we push out, so we shouldn't pull them in again.
        $tag = apply_filters( 'wghs_commit_msg_tag', 'wghs' );

        if ( ! $tag ) {
            throw new Exception( __( 'Commit message tag not set. Filter `wghs_commit_msg_tag` misconfigured.', 'wordpress-github-sync' ) );
        }

        if ( $tag === substr( $this->message(), -1 * strlen( $tag ) ) ) {
            return new WP_Error(
                'skip_import',
                'Skip import on auto export post.'
            );
        }

        if ( ! $this->get_commit_id() ) {
            return new WP_Error(
                'invalid_payload',
                "[Missing Commit ID] won't be imported."
            );
        }

        return true;
    }

    public function get_before_commit_id() {
        return $this->data->before ? $this->data->before : null;
    }

    /**
     * Returns the sha of the head commit.
     *
     * @return string
     */
    public function get_commit_id() {
        return $this->data->head_commit ? $this->data->head_commit->id : null;
    }

    /**
     * Returns the email address for the commit author.
     *
     * @return string
     */
    public function get_author_email() {
        return $this->data->head_commit->author->email;
    }

    /**
     * Returns array commits for the payload.
     *
     * @return array
     */
    public function get_commits() {
        return $this->data->commits;
    }

    /**
     * Returns the repository's full name.
     *
     * @return string
     */
    public function get_repository_name() {
        return $this->data->repository->full_name;
    }

    /**
     * Returns the payload's commit message.
     *
     * @return string
     */
    protected function message() {
        return $this->data->head_commit->message;
    }
}
