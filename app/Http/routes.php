<?php

$router = new \FluentSnippets\App\Services\Router('fluent-snippets');

$permissions = ['install_plugins'];

/*
 * Snippet creation and updating are NOT here. Both live on admin-ajax
 * (wp_ajax_fluent_snippet_create / wp_ajax_fluent_snippet_update in AdminMenuHandler),
 * which is what the editor calls. Three REST routes — POST snippets, snippets/create
 * and snippets/update — duplicated them and were called by nothing; they were removed
 * on 2026-08-11 (L7) so there is one way in per operation. Both paths always shared
 * their logic via Helper::createSnippet()/updateSnippet(), so nothing behavioural was
 * lost. Restore them here if an integration turns out to need REST access.
 */
$router->get('snippets', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'getSnippets'], $permissions)
    ->get('snippets/find', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'findSnippet'], $permissions)
    ->post('snippets/sync-index', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'syncIndex'], $permissions)
    ->post('snippets/update_status', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'updateSnippetStatus'], $permissions)
    ->post('snippets/delete_snippet', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'deleteSnippet'], $permissions)
    ->get('settings', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'getSettings'], $permissions)
    ->post('settings', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'saveSettings'], $permissions)
    ->post('settings/disable-safe-mode', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'disableSafeMode'], $permissions)
    ->post('settings/standalone', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'configStandAloneSystem'], $permissions)
    ->get('settings/options', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'getRestOptions'], $permissions)
    ->post('install_plugin', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'installPlugin'], $permissions);
