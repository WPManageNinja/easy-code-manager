<?php
/**
 * Plugin Name:  Fluent Snippets
 * Plugin URI:   https://fluentsnippets.com
 * Description:  Super Fast File Based Native Code Snippets (header / footer codes) Manager for WordPress
 * Author:       Fluent Snippets
 * Author URI:   https://fluentsnippets.com
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  easy-code-manager
 * Version:     10.34
 * Requires PHP: 7.3
 * Requires at least: 6.0
 * Domain Path:  /language
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    return;
}

if (defined('FLUENT_SNIPPETS_PLUGIN_PATH')) {
    return;
}

define('FLUENT_SNIPPETS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLUENT_SNIPPETS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLUENT_SNIPPETS_PLUGIN_VERSION', '10.34');

class FluentCodeSnippetsBoot
{
    public function boot()
    {
        $this->autoLoad();

        add_action('init', function () {
            load_plugin_textdomain('easy-code-manager', false, 'fluent-snippets/language');
        });
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

        register_deactivation_hook(__FILE__, [\FluentSnippets\App\Helpers\Helper::class, 'handleDeactivate']);
    }
}

(new FluentCodeSnippetsBoot())->boot();
