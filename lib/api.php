<?php
/**
 * Interfaces with the GitHub API
 * @package Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Api
 */
class Wordpress_GitHub_Sync_Api {

    /**
     * Application container.
     *
     * @var Wordpress_GitHub_Sync
     */
    protected $app;

    /**
     * GitHub fetch client.
     *
     * @var Wordpress_GitHub_Sync_Fetch_Client
     */
    protected $fetch;

    /**
     * Github persist client.
     *
     * @var Wordpress_GitHub_Sync_Persist_Client
     */
    protected $persist;

    /**
     * Instantiates a new Api object.
     *
     * @param Wordpress_GitHub_Sync $app Application container.
     */
    public function __construct( Wordpress_GitHub_Sync $app ) {
        $this->app = $app;
    }

    /**
     * Lazy-load fetch client.
     *
     * @return Wordpress_GitHub_Sync_Fetch_Client
     */
    public function fetch() {
        if ( ! $this->fetch ) {
            $this->fetch = new Wordpress_GitHub_Sync_Fetch_Client( $this->app );
        }

        return $this->fetch;
    }

    /**
     * Lazy-load persist client.
     *
     * @return Wordpress_GitHub_Sync_Persist_Client
     */
    public function persist() {
        if ( ! $this->persist ) {
            $this->persist = new Wordpress_GitHub_Sync_Persist_Client( $this->app );
        }

        return $this->persist;
    }
}
