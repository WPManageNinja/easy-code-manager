<?php

namespace FluentSnippets\App\Services;

/**
 * The other free plugins by the same team, and where each one stands on this site.
 *
 * This exists for one card at the bottom of the About screen. Two deliberate limits on
 * what goes in it:
 *
 *   1. Free plugins from the WordPress.org directory only. The About screen's own copy
 *      says there is no paid tier and no upgrade prompt, and a grid of "Pro" badges
 *      underneath it would make that a lie. "More free things by the same people" is a
 *      different claim and a true one.
 *   2. The list is written out here rather than fetched from the directory's author
 *      endpoint. A card that has to wait on api.wordpress.org is a card that is blank on
 *      a site behind a firewall, and the descriptions below say what each plugin is for
 *      in this plugin's voice rather than in the words of a directory listing.
 *
 * Only the icons are remote (ps.w.org, the directory's own asset host), which is what the
 * Add Plugins screen does too. A failed icon costs the card its picture and nothing else -
 * see the fallback in _TeamPlugins.vue.
 */
class TeamPlugins
{
    /**
     * The catalogue plugins worth offering this site, with their state resolved.
     *
     * `state` is one of:
     *   available - not on the site at all; offer to install it
     *   installed - present but switched off; offer to activate it
     *   active    - already running
     *
     * Anything already active is left out. This is a list of suggestions, and a row
     * saying "you have this" is not a suggestion - on a site running most of them the
     * card was mostly a checklist of things the user could do nothing about.
     *
     * Note where the filtering happens: only at page load. A plugin the user installs
     * without leaving the screen was in the payload when the screen was drawn, so it
     * stays in the list and turns into "Active" where they clicked - which is the
     * confirmation that the click worked. It is gone the next time the screen loads.
     *
     * @return array
     */
    public static function get()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = get_plugins();

        $plugins = [];

        foreach (self::catalogue() as $slug => $plugin) {
            $file = self::findPluginFile($slug, $installed);
            $state = self::stateOf($file);

            if ($state === 'active') {
                continue;
            }

            $plugins[] = [
                'slug'        => $slug,
                'name'        => $plugin['name'],
                'description' => $plugin['description'],
                'icon'        => 'https://ps.w.org/' . $slug . '/assets/' . $plugin['icon'],
                'url'         => 'https://wordpress.org/plugins/' . $slug . '/',
                /*
                 * The file the activate-plugin endpoint wants, e.g. fluent-crm/fluent-crm.php.
                 * Null until the plugin is on disk; the browser fills it in from the install
                 * response for anything it installs itself.
                 */
                'file'        => $file,
                'state'       => $state,
            ];
        }

        return apply_filters('fluent_snippets/team_plugins', $plugins);
    }

    /**
     * What the browser needs to know before it draws a button.
     *
     * The screen this appears on already requires install_plugins, so can_install is all
     * but guaranteed - it is checked anyway because a filter on
     * `fluent_snippets/team_plugins` could put this list somewhere else. can_activate is
     * the one that genuinely varies: a role can be allowed to install a plugin without
     * being allowed to switch anything on.
     *
     * Both are re-checked by core in wp_ajax_install_plugin / wp_ajax_activate_plugin.
     * These only decide which buttons are worth drawing.
     *
     * plugins_screen is the fallback for the one case the inline flow cannot finish by
     * itself - see _TeamPlugins.vue.
     *
     * @return array
     */
    public static function config()
    {
        return [
            'can_install'    => current_user_can('install_plugins'),
            'can_activate'   => current_user_can('activate_plugins'),
            'plugins_screen' => admin_url('plugins.php'),
        ];
    }

    /**
     * Download and unpack one catalogue plugin from the WordPress.org directory.
     *
     * The slug is looked up in the catalogue rather than trusted, which is the control
     * that matters here: it means this route installs one of eleven known plugins or
     * nothing, instead of being a general-purpose "install any plugin by name" endpoint.
     * A user holding install_plugins can already install anything from the Add Plugins
     * screen, so this is not closing a privilege hole - it is keeping the endpoint's
     * blast radius equal to what the button in front of it advertises.
     *
     * @param string $slug
     * @return array|\WP_Error The plugin's new state, or why it could not be installed.
     */
    public static function install($slug)
    {
        if (!current_user_can('install_plugins')) {
            return new \WP_Error('permission_denied', __('You do not have permission to install plugins on this site.', 'easy-code-manager'));
        }

        $catalogue = self::catalogue();

        if (empty($catalogue[$slug])) {
            return new \WP_Error('unknown_plugin', __('That plugin is not one of the plugins this screen offers.', 'easy-code-manager'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Already on disk: nothing to download, and re-installing over a plugin the user
        // may have updated is not what the button offered to do.
        if ($file = self::findPluginFile($slug, get_plugins())) {
            return self::stateFor($slug, $file);
        }

        if (!WP_Filesystem()) {
            return new \WP_Error('filesystem_unavailable', __('WordPress could not get write access to the plugins directory. This usually means the site needs FTP credentials to install anything, in which case installing from the Plugins screen will work and this button will not.', 'easy-code-manager'));
        }

        $info = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => ['sections' => false]
        ]);

        if (is_wp_error($info)) {
            return new \WP_Error('directory_unreachable', sprintf(
                /* translators: %s: the error reported by WordPress.org. */
                __('WordPress.org could not be reached to fetch the plugin: %s', 'easy-code-manager'),
                $info->get_error_message()
            ));
        }

        if (empty($info->download_link)) {
            return new \WP_Error('no_download_link', __('WordPress.org did not offer a download for that plugin.', 'easy-code-manager'));
        }

        $upgrader = new \WP_Upgrader(new \Automatic_Upgrader_Skin());

        /*
         * The skin prints its progress as it goes. This is a REST route, so that output
         * would land in front of the JSON - the Router buffers callbacks for exactly this
         * reason, but it costs nothing to not make the mess in the first place.
         */
        ob_start();

        $result = self::runUpgrader($upgrader, $info->download_link);

        ob_end_clean();

        if (is_wp_error($result)) {
            return $result;
        }

        // Written by the install, and stale the moment it was: get_plugins() caches.
        wp_clean_plugins_cache();

        $file = self::findPluginFile($slug, get_plugins());

        if (!$file) {
            return new \WP_Error('install_incomplete', __('The plugin downloaded but WordPress cannot find it on disk afterwards. Check the Plugins screen before trying again.', 'easy-code-manager'));
        }

        return self::stateFor($slug, $file);
    }

    /**
     * Switch on a catalogue plugin that is already installed.
     *
     * @param string $slug
     * @return array|\WP_Error
     */
    public static function activate($slug)
    {
        $catalogue = self::catalogue();

        if (empty($catalogue[$slug])) {
            return new \WP_Error('unknown_plugin', __('That plugin is not one of the plugins this screen offers.', 'easy-code-manager'));
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $file = self::findPluginFile($slug, get_plugins());

        if (!$file) {
            return new \WP_Error('not_installed', __('That plugin is not installed on this site.', 'easy-code-manager'));
        }

        /*
         * activate_plugins is the broad capability; the per-plugin check is the one core
         * itself uses, and on multisite it is what distinguishes a plugin a site admin
         * may switch on from one only the network may.
         */
        if (!current_user_can('activate_plugins') || !current_user_can('activate_plugin', $file)) {
            return new \WP_Error('permission_denied', __('You do not have permission to activate plugins on this site.', 'easy-code-manager'));
        }

        if (is_plugin_active($file)) {
            return self::stateFor($slug, $file);
        }

        /*
         * The plugin's own activation code runs inside this call. If it fatals, it takes
         * the request with it - which is why $silent stays false: WordPress wraps the
         * activation in its own error handling and hands back a WP_Error describing the
         * fatal instead of leaving a half-activated plugin behind.
         */
        $activated = activate_plugin($file);

        if (is_wp_error($activated)) {
            return $activated;
        }

        return self::stateFor($slug, $file);
    }

    /**
     * The download-unpack-install sequence, as three steps that each stop on failure.
     *
     * Plugin_Upgrader::install() would do this in one call, but it also insists on a skin
     * that renders a full-page result screen and it swallows the specific failure into a
     * generic one. These are the same three methods it calls, with the error from
     * whichever step failed passed straight back.
     *
     * @param \WP_Upgrader $upgrader
     * @param string $package
     * @return true|\WP_Error
     */
    private static function runUpgrader($upgrader, $package)
    {
        $download = $upgrader->download_package($package);

        if (is_wp_error($download)) {
            return $download;
        }

        $workingDir = $upgrader->unpack_package($download, true);

        if (is_wp_error($workingDir)) {
            return $workingDir;
        }

        $installed = $upgrader->install_package([
            'source'                      => $workingDir,
            'destination'                 => WP_PLUGIN_DIR,
            'clear_destination'           => false,
            'abort_if_destination_exists' => false,
            'clear_working'               => true,
            'hook_extra'                  => [
                'type'   => 'plugin',
                'action' => 'install'
            ]
        ]);

        if (is_wp_error($installed)) {
            return $installed;
        }

        return true;
    }

    /**
     * The subset of a catalogue entry the browser needs after something has changed.
     *
     * @param string $slug
     * @param string $file
     * @return array
     */
    private static function stateFor($slug, $file)
    {
        return [
            'slug'  => $slug,
            'file'  => $file,
            'state' => self::stateOf($file)
        ];
    }

    /**
     * @param string|null $file
     * @return string
     */
    private static function stateOf($file)
    {
        if (!$file) {
            return 'available';
        }

        // Covers network activation on multisite as well as the ordinary case.
        return is_plugin_active($file) ? 'active' : 'installed';
    }

    /**
     * The plugin file belonging to a directory slug, if the plugin is on disk.
     *
     * Matching on the directory rather than searching the file names is what keeps the
     * Pro add-ons out of this: FluentCRM's is `fluentcampaign-pro/fluentcampaign-pro.php`
     * and is a separate plugin that requires the free one, so it must not be mistaken for
     * it. A plugin installed to a renamed directory is reported as not installed, which
     * costs the user one redundant install rather than a broken activate call.
     *
     * @param string $slug
     * @param array $installed Result of get_plugins(), keyed by plugin file.
     * @return string|null
     */
    private static function findPluginFile($slug, $installed)
    {
        foreach ($installed as $file => $data) {
            if (dirname($file) === $slug) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The catalogue itself.
     *
     * The icon is a file name rather than a URL because the directory serves some of
     * these as SVG and some as PNG, and there is no way to tell which from the slug.
     *
     * Descriptions say what the plugin does, in the same register as the rest of the
     * About screen: what happens, not how good it is.
     *
     * @return array
     */
    private static function catalogue()
    {
        return [
            'fluentform'      => [
                'name'        => 'Fluent Forms',
                'icon'        => 'icon-128x128.png',
                'description' => __('Contact forms, surveys and quizzes, built by dragging fields into place.', 'easy-code-manager'),
            ],
            'fluent-smtp'     => [
                'name'        => 'FluentSMTP',
                'icon'        => 'icon.svg',
                'description' => __('Sends your site\'s mail through a real mail service, so it stops arriving in spam folders.', 'easy-code-manager'),
            ],
            'fluent-crm'      => [
                'name'        => 'FluentCRM',
                'icon'        => 'icon.svg',
                'description' => __('Email lists, campaigns and automations, kept on your own site rather than rented.', 'easy-code-manager'),
            ],
            'ninja-tables'    => [
                'name'        => 'Ninja Tables',
                'icon'        => 'icon-128x128.png',
                'description' => __('Data tables you edit like a spreadsheet and place with a shortcode.', 'easy-code-manager'),
            ],
            'fluent-booking'  => [
                'name'        => 'FluentBooking',
                'icon'        => 'icon.svg',
                'description' => __('Appointment scheduling that syncs both ways with Google, Outlook and Apple calendars.', 'easy-code-manager'),
            ],
            'fluent-support'  => [
                'name'        => 'Fluent Support',
                'icon'        => 'icon.svg',
                'description' => __('A ticketed helpdesk that runs inside WordPress instead of a third-party inbox.', 'easy-code-manager'),
            ],
            'fluent-security' => [
                'name'        => 'FluentAuth',
                'icon'        => 'icon.svg',
                'description' => __('Two-factor sign-in, magic links, and a record of every login attempt on the site.', 'easy-code-manager'),
            ],
            'fluent-community' => [
                'name'        => 'FluentCommunity',
                'icon'        => 'icon.svg',
                'description' => __('Spaces, courses and member feeds - a social network on your own domain.', 'easy-code-manager'),
            ],
            'fluent-cart'     => [
                'name'        => 'FluentCart',
                'icon'        => 'icon.svg',
                'description' => __('A shop: products, carts, subscriptions and checkout.', 'easy-code-manager'),
            ],
            'fluent-boards'   => [
                'name'        => 'FluentBoards',
                'icon'        => 'icon.svg',
                'description' => __('Kanban boards and task lists for the people who run the site.', 'easy-code-manager'),
            ],
            'wp-payment-form' => [
                'name'        => 'Paymattic',
                'icon'        => 'icon-128x128.png',
                'description' => __('Payment and donation forms, one-off or recurring.', 'easy-code-manager'),
            ],
        ];
    }
}
