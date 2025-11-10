<?php

namespace TypesenseIndex;

class App
{

    public function __construct()
    {
        //Config page
        new Admin\Settings();

        //Admin pages
        new Admin\Post();

        // Admin
        new Admin\Actions();

        if (Helper\Options::isConfigured()) {
            new Index(); // Indexing hooks for posts
            new Search(); // Search integration
        }

        // WP CLI commands
        if (defined('WP_CLI') && constant('WP_CLI') === true) {
            new CLI();
        }
    }
}
