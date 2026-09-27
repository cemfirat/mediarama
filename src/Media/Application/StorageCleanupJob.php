<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\StorageObjectId;
use Symfony\Component\Uid\Uuid;

final readonly class StorageCleanupJob
{
    public function __construct(
        public Uuid $id,
        public StorageObjectId $storage,
    ) {
    }
}
