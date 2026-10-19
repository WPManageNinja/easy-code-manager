<?php

namespace FluentSnippets\App\Services;

// Fixture for tests/lint/translation-map.js --self-test: deliberately stale.
// It lacks every string in src/Bad.vue and carries one that no longer exists.
class TransStrings
{
    public static function getStrings()
    {
        return [
            'A string that left the source' => __('A string that left the source', 'easy-code-manager'),
        ];
    }
}
