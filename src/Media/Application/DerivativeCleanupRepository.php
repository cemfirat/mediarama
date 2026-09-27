<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface DerivativeCleanupRepository
{
    public function previewSuperseded(
        DateTimeImmutable $cutoff,
        int $keepVersions,
        int $generationLimit,
    ): DerivativeCleanupSummary;

    public function enqueueSuperseded(
        DateTimeImmutable $cutoff,
        int $keepVersions,
        int $generationLimit,
    ): DerivativeCleanupSummary;

    /** @return list<StorageCleanupJob> */
    public function claimStorageJobs(
        int $limit,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $staleBefore,
    ): array;

    public function completeStorageJob(Uuid $id): void;

    public function failStorageJob(Uuid $id, string $error): void;

    public function pendingStorageJobCount(): int;
}
