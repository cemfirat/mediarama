<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

final readonly class DerivativeCleanupReport
{
    public function __construct(
        public int $stagedObjects,
        public int $completedObjects,
        public int $failedObjects,
    ) {
    }
}
