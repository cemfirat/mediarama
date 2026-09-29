<?php

declare(strict_types=1);

namespace Mediarama\Import\Application;

interface ImportCheckpointRepository
{
    public function get(string $sourceKey, string $stage): ?string;

    public function save(string $sourceKey, string $stage, string $cursor): void;

    public function clear(string $sourceKey, string $stage): void;
}
