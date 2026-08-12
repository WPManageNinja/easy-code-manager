<?php

namespace FluentSnippets\App\Services;

/**
 * Who may look at snippets, and who may change them.
 *
 * These used to be the same question. Everything - the admin menu, every REST route,
 * every ajax handler - was gated on `install_plugins`, which is the right capability for
 * writing a snippet: a snippet is executable PHP, and handing someone the ability to add
 * it is handing them the ability to install a plugin.
 *
 * It is the wrong capability for reading one, and there is a configuration that makes the
 * difference matter. `define('DISALLOW_FILE_MODS', true)` in wp-config.php takes
 * install_plugins away from every user on the site, including administrators - that is
 * core's own doing, in map_meta_cap(). Managed hosts set it as a matter of course, and
 * anyone deploying wp-content from version control sets it deliberately. On those sites
 * the whole plugin vanished: no menu entry, and the page 403'd if you reached it by URL,
 * with nothing anywhere to say why. The snippets were still on disk and still running.
 *
 * So the two questions are now separate. Reading needs the audience install_plugins
 * would have had; writing still needs install_plugins itself, and stays refused while
 * file modifications are off - which is exactly what that constant is asking for, since
 * every snippet is a file in wp-content.
 */
class Permissions
{
    /**
     * The capability that opens the screen.
     *
     * Normally install_plugins, so nothing about who can reach this plugin changes on an
     * ordinary site. When file modifications are off, install_plugins is unavailable to
     * everyone and a stand-in is needed - one that reaches the same people it would have,
     * no more:
     *
     *   single site - `activate_plugins`, which administrators have and nobody below them
     *                 does. Untouched by the file-modification rules.
     *   multisite   - `manage_network_plugins`, i.e. Super Admins. install_plugins is
     *                 already Super-Admin-only there (map_meta_cap denies it to everyone
     *                 else on a network), so anything site-level would be a widening.
     *
     * The context string is core's own for this branch of map_meta_cap, so a site that
     * filters `file_mod_allowed` per context gets a consistent answer from us and from
     * WordPress.
     *
     * @return string
     */
    public static function viewCapability()
    {
        if (wp_is_file_mod_allowed('capability_update_core')) {
            return 'install_plugins';
        }

        return is_multisite() ? 'manage_network_plugins' : 'activate_plugins';
    }

    /**
     * @return bool
     */
    public static function canView()
    {
        return current_user_can(self::viewCapability());
    }

    /**
     * Whether this user may create, change, delete or import a snippet.
     *
     * Authoring paths ask for `unfiltered_html` on top of this, with a message of their
     * own - see AdminMenuHandler::guardAuthoringRequest(). This is the capability every
     * write shares, and the one the read-only screen is built around.
     *
     * @return bool
     */
    public static function canEdit()
    {
        return current_user_can('install_plugins');
    }

    /**
     * Can see the screen, cannot change anything on it.
     *
     * @return bool
     */
    public static function isReadOnly()
    {
        return self::canView() && !self::canEdit();
    }

    /**
     * Why the screen is read-only, for the banner across the top of it.
     *
     * There is only one way to end up here, which is worth stating because it is not
     * obvious from isReadOnly() alone. While file modifications are allowed, viewing and
     * editing both resolve to install_plugins, so anyone who can open the screen can also
     * change things and this never fires. Turning file modifications off is what pulls
     * the two apart. A user who merely lacks install_plugins does not get a read-only
     * screen - they get no screen, exactly as before.
     *
     * So this says which line in which file, because whoever is reading it is on a site
     * that was configured this way deliberately and needs to know where to look.
     *
     * @return array{title: string, reason: string}
     */
    public static function readOnlyNotice()
    {
        return [
            'title'  => __('Snippets are read-only on this site', 'easy-code-manager'),
            'reason' => __('wp-config.php has DISALLOW_FILE_MODS set, which tells WordPress that nothing may write to wp-content - and every snippet is a file in there. You can read, search and export your snippets, but not add, change, enable, disable or delete one. Snippets that are already active carry on running exactly as before. Remove that line, or deploy your change through whatever puts files on this server, to edit them here again.', 'easy-code-manager'),
        ];
    }
}
