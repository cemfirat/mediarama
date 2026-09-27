<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

final readonly class DerivativeCleanupResult
{
    public function __construct(
        public DerivativeCleanupSummary $queued,
        public int $storageDeleted,
        public int $storageFailed,
        public int $storagePending,
    ) {
        if ($storageDeleted < 0 || $storageFailed < 0 || $storagePending < 0) {
            throw new \InvalidArgumentException('Derivative cleanup result values must be non-negative.');
        }
    }
}
