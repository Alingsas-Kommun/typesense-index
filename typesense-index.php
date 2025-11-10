<?php

/**
 * Plugin Name:       Typesense Index
 * Plugin URI:        https://github.com/michaelclaesson/typesense-index
 * Description:       Manages Typesense index
 * Version:           1.0.1
 * Author:            Michael Claesson
 * Author URI:        https://github.com/michaelclaesson
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       typesense-index
 * Domain Path:       /languages
 */

 // Protect agains direct file access
if (! defined('WPINC')) {
    die;
}

define('TYPESENSEINDEX_PATH', plugin_dir_path(__FILE__));
define('TYPESENSEINDEX_URL', plugins_url('', __FILE__));
define('TYPESENSEINDEX_TEMPLATE_PATH', TYPESENSEINDEX_PATH . 'templates/');

load_plugin_textdomain('typesense-index', false, plugin_basename(dirname(__FILE__)) . '/languages');

// Autoload from plugin
if (file_exists(TYPESENSEINDEX_PATH . 'vendor/autoload.php')) {
    require_once TYPESENSEINDEX_PATH . 'vendor/autoload.php';
}

require_once TYPESENSEINDEX_PATH . 'Public.php';

// Start application
new TypesenseIndex\App();