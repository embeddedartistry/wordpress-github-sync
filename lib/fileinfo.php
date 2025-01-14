<?php


/**
 *
 */
class Wordpress_GitHub_Sync_File_Info {

    public function __construct( stdClass $data ) {
        $this->sha          = $data->sha;
        $this->path         = $data->path;
        $this->status       = $data->status;
    }

    public $sha;
    public $path;
    public $status;  // added removed modified
}
