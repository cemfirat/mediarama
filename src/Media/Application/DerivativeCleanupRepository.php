<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;
use Mediarama\Media\Domain\MediaDerivative;

interface DerivativeCleanupRepository
{
    public function stageSuperseded(
        DateTimeImmutable $cutoff,
        int $keepNewestVersions,
        int $limitVersions,
    ): int;

    /** @return list<DerivativeCleanupJob> */
    public function pending(int $limit): array;

    public function complete(int $jobId): void;

    public function enqueueOrphan(MediaDerivative $derivative): void;
}
