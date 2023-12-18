<?php

$router = new \FluentSnippets\App\Services\Router('fluent-snippets');

$permissions = ['install_plugins'];

$router->get('snippets', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'getSnippets'], $permissions)
    ->post('snippets', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'createSnippet'], $permissions)
    ->get('snippets/find', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'findSnippet'], $permissions)
    ->post('snippets/create', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'createSnippet'], $permissions)
    ->post('snippets/update', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'updateSnippet'], $permissions)
    ->post('snippets/update_status', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'updateSnippetStatus'], $permissions)
    ->post('snippets/delete_snippet', ['\FluentSnippets\App\Http\Controllers\SnippetsController', 'deleteSnippet'], $permissions)
    ->get('settings', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'getSettings'], $permissions)
    ->post('settings', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'saveSettings'], $permissions)
    ->post('settings/disable-safe-mode', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'disableSafeMode'], $permissions)
    ->post('settings/standalone', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'configStandAloneSystem'], $permissions)
    ->get('settings/options', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'getRestOptions'], $permissions)
    ->post('install_plugin', ['\FluentSnippets\App\Http\Controllers\SettingsController', 'installPlugin'], $permissions);
