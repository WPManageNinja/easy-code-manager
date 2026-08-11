<?php

use FluentSnippets\App\Services\Permissions;

$router = new \FluentSnippets\App\Services\Router('fluent-snippets');

/*
 * Two gates, not one. Reading a snippet and writing one are different privileges, and on
 * a site with DISALLOW_FILE_MODS set they land on different sides of the line - see
 * Services/Permissions. On an ordinary site both of these resolve to install_plugins and
 * nothing about who can call what has changed.
 *
 * $read is deliberately never applied to anything that writes. The controllers guard
 * themselves as well (denyUnlessCanAuthorSnippets / denyUnlessCanManageSettings), so a
 * write route is refused twice over; that redundancy is the point, because it means
 * loosening a route gate here cannot quietly open a write path.
 */
$read = [Permissions::viewCapability()];
$write = ['install_plugins'];

/*
 * Snippet creation and updating are NOT here. Both live on admin-ajax
 * (wp_ajax_fluent_snippet_create / wp_ajax_fluent_snippet_update in AdminMenuHandler),
 * which is what the editor calls. Three REST routes — POST snippets, snippets/create
 * and snippets/update — duplicated them and were called by nothing; they were removed
 * on 2026-08-11 (L7) so there is one way in per operation. Both paths always shared
 * their logic via Helper::createSnippet()/updateSnippet(), so nothing behavioural was
 * lost. Restore them here if an integration turns out to need REST access.
 */
$router->get('snippets', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'getSnippets'], $read)
    ->get('snippets/find', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'findSnippet'], $read)
    /*
     * A POST, but a read as far as this distinction goes: syncSnippetIndex() rewrites the
     * plugin's own index cache to match the files on disk and never touches a snippet.
     * It is also a no-op unless something out there has already changed.
     *
     * It matters most on exactly the sites this split was written for. When file
     * modifications are off, snippets arrive by deployment rather than through this
     * screen - and this route is what makes a deployed snippet appear in the list
     * instead of the visitor staring at a stale one.
     */
    ->post('snippets/sync-index', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'syncIndex'], $read)
    ->post('snippets/update_status', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'updateSnippetStatus'], $write)
    ->post('snippets/delete_snippet', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'deleteSnippet'], $write)
    ->get('settings', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'getSettings'], $read)
    ->post('settings', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'saveSettings'], $write)
    ->post('settings/disable-safe-mode', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'disableSafeMode'], $write)
    ->post('settings/regenerate-secret', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'regenerateSecretUrl'], $write)
    ->post('settings/standalone', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'configStandAloneSystem'], $write)
    ->get('settings/options', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'getRestOptions'], $read)
    /*
     * The "from the same team" card on the About screen. Installing a plugin is a file
     * modification like any other, so these stay on $write and disappear along with the
     * rest of the editing surface when file modifications are off.
     */
    ->post('team-plugins/install', ['\FluentSnippets\App\Http\Controllers\TeamPluginsController', 'installPlugin'], $write)
    ->post('team-plugins/activate', ['\FluentSnippets\App\Http\Controllers\TeamPluginsController', 'activatePlugin'], $write);
