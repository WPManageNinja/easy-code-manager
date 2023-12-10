<?php

namespace FluentSnippets\App\Hooks\Handlers;

use FluentSnippets\App\Helpers\Helper;
use FluentSnippets\App\Model\Snippet;
use FluentSnippets\App\Services\OldPluginMigrator;

class UpgradeHandler
{
    public function maybeUpgradeFromOld()
    {
        $config = Helper::getIndexedConfig();
        if ($config) {
            return false;
        }

        $oldSnippets = OldPluginMigrator::getOldSnippets();
        if (!$oldSnippets) {
            return false;
        }

        Helper::cacheSnippetIndex('', false, [
            'legacy_status' => 'migrating'
        ]);

        $snippetModel = new Snippet();

        foreach ($oldSnippets as $snippet) {
            $snippetModel->createSnippet($snippet['code'], $snippet['meta']);
        }

        Helper::cacheSnippetIndex('', false, [
            'legacy_status' => 'migrated'
        ]);
    }
}
