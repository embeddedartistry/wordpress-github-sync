<?php

abstract class Wordpress_GitHub_Sync_TestCase extends WP_HTTP_TestCase {

    /**
     * @var string
     */
    protected $data_dir;

    /**
     * @var Wordpress_GitHub_Sync|Mockery\Mock
     */
    protected $app;

    /**
     * @var Wordpress_GitHub_Sync_Controller|Mockery\Mock
     */
    protected $controller;

    /**
     * @var Wordpress_GitHub_Sync_Request|Mockery\Mock
     */
    protected $request;

    /**
     * @var Wordpress_GitHub_Sync_Import|Mockery\Mock
     */
    protected $import;

    /**
     * @var Wordpress_GitHub_Sync_Export|Mockery\Mock
     */
    protected $export;

    /**
     * @var Wordpress_GitHub_Sync_Response|Mockery\Mock
     */
    protected $response;

    /**
     * @var Wordpress_GitHub_Sync_Payload|Mockery\Mock
     */
    protected $payload;

    /**
     * @var Wordpress_GitHub_Sync_Api|Mockery\Mock
     */
    protected $api;

    /**
     * @var Wordpress_GitHub_Sync_Semaphore|Mockery\Mock
     */
    protected $semaphore;

    /**
     * @var Wordpress_GitHub_Sync_Database|Mockery\Mock
     */
    protected $database;

    /**
     * @var Wordpress_GitHub_Sync_Post|Mockery\Mock
     */
    protected $post;

    /**
     * @var Wordpress_GitHub_Sync_Blob|Mockery\Mock
     */
    protected $blob;

    /**
     * @var Wordpress_GitHub_Sync_Fetch_Client|Mockery\Mock
     */
    protected $fetch;

    /**
     * @var Wordpress_GitHub_Sync_Persist_Client|Mockery\Mock
     */
    protected $persist;

    public function setUp() {
        parent::setUp();

        $this->data_dir = dirname( __DIR__ ) . '/data/';

        $this->app        = Mockery::mock( 'Wordpress_GitHub_Sync' );
        $this->controller = Mockery::mock( 'Wordpress_GitHub_Sync_Controller' );
        $this->request    = Mockery::mock( 'Wordpress_GitHub_Sync_Request' );
        $this->import     = Mockery::mock( 'Wordpress_GitHub_Sync_Import' );
        $this->export     = Mockery::mock( 'Wordpress_GitHub_Sync_Export' );
        $this->response   = Mockery::mock( 'Wordpress_GitHub_Sync_Response' );
        $this->payload    = Mockery::mock( 'Wordpress_GitHub_Sync_Payload' );
        $this->api        = Mockery::mock( 'Wordpress_GitHub_Sync_Api' );
        $this->semaphore  = Mockery::mock( 'Wordpress_GitHub_Sync_Semaphore' );
        $this->database   = Mockery::mock( 'Wordpress_GitHub_Sync_Database' );
        $this->post       = Mockery::mock( 'Wordpress_GitHub_Sync_Post' );
        $this->blob       = Mockery::mock( 'Wordpress_GitHub_Sync_Blob' );
        $this->fetch      = Mockery::mock( 'Wordpress_GitHub_Sync_Fetch_Client' );
        $this->persist    = Mockery::mock( 'Wordpress_GitHub_Sync_Persist_Client' );

        Wordpress_GitHub_Sync::$instance = $this->app;

        $this->app
            ->shouldReceive( 'request' )
            ->andReturn( $this->request )
            ->byDefault();
        $this->app
            ->shouldReceive( 'import' )
            ->andReturn( $this->import )
            ->byDefault();
        $this->app
            ->shouldReceive( 'export' )
            ->andReturn( $this->export )
            ->byDefault();
        $this->app
            ->shouldReceive( 'response' )
            ->andReturn( $this->response )
            ->byDefault();
        $this->app
            ->shouldReceive( 'api' )
            ->andReturn( $this->api )
            ->byDefault();
        $this->app
            ->shouldReceive( 'semaphore' )
            ->andReturn( $this->semaphore )
            ->byDefault();
        $this->app
            ->shouldReceive( 'database' )
            ->andReturn( $this->database )
            ->byDefault();
        $this->app
            ->shouldReceive( 'blob' )
            ->andReturn( $this->blob )
            ->byDefault();
        $this->api
            ->shouldReceive( 'fetch' )
            ->andReturn( $this->fetch )
            ->byDefault();
        $this->api
            ->shouldReceive( 'persist' )
            ->andReturn( $this->persist )
            ->byDefault();
    }

    public function tearDown() {
        Mockery::close();
    }
}
