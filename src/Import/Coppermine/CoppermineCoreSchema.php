<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

final class CoppermineCoreSchema
{
    /** @var list<string> */
    public const TABLE_SUFFIXES = [
        'albums',
        'banned',
        'bridge',
        'categories',
        'categorymap',
        'comments',
        'config',
        'dict',
        'ecards',
        'exif',
        'favpics',
        'filetypes',
        'hit_stats',
        'languages',
        'pictures',
        'plugins',
        'sessions',
        'temp_messages',
        'usergroups',
        'users',
        'votes',
        'vote_stats',
    ];

    /** @var list<string> */
    public const REQUIRED_FOR_MIGRATION = [
        'pictures',
        'albums',
        'categories',
        'users',
        'usergroups',
        'comments',
        'votes',
        'vote_stats',
        'config',
        'languages',
    ];

    private function __construct()
    {
    }
}
