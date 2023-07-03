<?php
/**
 * Plugin Name:  Fluent Code Snippets
 * Plugin URI:   https://fluentforms.com
 * Description:  Super Fast File Based Native Code Snippets Manager for WordPress
 * Author:       Fluent Snippets
 * Author URI:   https://fluentforms.com
 * License:      GPL-2.0-or-later
 * License URI:  license.txt
 * Text Domain:  fluent-snippets
 * Version:     1.0.0
 * Requires PHP: 7.1
 * Requires at least: 5.0
 *
 *
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    return;
}

define('FLUENT_SNIPPETS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLUENT_SNIPPETS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLUENT_SNIPPETS_PLUGIN_VERSION', '1.0.0');

class FluentCodeSnippets
{
    public function boot()
    {
        $this->autoLoad();
    }

    private function autoLoad()
    {

        spl_autoload_register(function ($class) {
            $match = 'FluentSnippets';

            if (!preg_match("/\b{$match}\b/", $class)) {
                return;
            }

            $path = plugin_dir_path(__FILE__);

            $file = str_replace(
                ['FluentSnippets', '\\', '/App/'],
                ['', DIRECTORY_SEPARATOR, 'app/'],
                $class
            );

            require(trailingslashit($path) . trim($file, '/') . '.php');
        });

        add_action('plugins_loaded', function () {
            add_action('rest_api_init', function () {
                require_once FLUENT_SNIPPETS_PLUGIN_PATH . 'app/Http/routes.php';
            });
        });

        require_once FLUENT_SNIPPETS_PLUGIN_PATH . 'app/Hooks/hooks.php';
    }
}

$fluentCodeSnippets = new FluentCodeSnippets();
$fluentCodeSnippets->boot();
