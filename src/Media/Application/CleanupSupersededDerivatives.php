<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateInterval;
use DateTimeImmutable;

final readonly class CleanupSupersededDerivatives
{
    public const DEFAULT_KEEP_VERSIONS = 3;
    public const DEFAULT_RETENTION_DAYS = 400;
    public const DEFAULT_GENERATION_LIMIT = 100;
    public const DEFAULT_STORAGE_LIMIT = 500;

    public function __construct(
        private DerivativeCleanupRepository $repository,
        private MediaStorage $storage,
    ) {
    }

    public function preview(
        DateTimeImmutable $cutoff,
        int $keepVersions = self::DEFAULT_KEEP_VERSIONS,
        int $generationLimit = self::DEFAULT_GENERATION_LIMIT,
    ): DerivativeCleanupSummary {
        $this->validate($keepVersions, $generationLimit);

        return $this->repository->previewSuperseded($cutoff, $keepVersions, $generationLimit);
    }

    public function run(
        DateTimeImmutable $cutoff,
        int $keepVersions = self::DEFAULT_KEEP_VERSIONS,
        int $generationLimit = self::DEFAULT_GENERATION_LIMIT,
        int $storageLimit = self::DEFAULT_STORAGE_LIMIT,
    ): DerivativeCleanupResult {
        $this->validate($keepVersions, $generationLimit);

        if ($storageLimit < 1 || $storageLimit > 10000) {
            throw new \InvalidArgumentException('Storage cleanup limit must be between 1 and 10000.');
        }

        $queued = $this->repository->enqueueSuperseded($cutoff, $keepVersions, $generationLimit);
        $now = new DateTimeImmutable();
        $jobs = $this->repository->claimStorageJobs(
            $storageLimit,
            $now,
            $now->sub(new DateInterval('PT15M')),
        );

        $deleted = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            try {
                if ($this->storage->exists($job->storage)) {
                    $this->storage->delete($job->storage);
                }

                $this->repository->completeStorageJob($job->id);
                ++$deleted;
            } catch (\Throwable) {
                $this->repository->failStorageJob($job->id, 'storage_delete_failed');
                ++$failed;
            }
        }

        return new DerivativeCleanupResult(
            $queued,
            $deleted,
            $failed,
            $this->repository->pendingStorageJobCount(),
        );
    }

    private function validate(int $keepVersions, int $generationLimit): void
    {
        if ($keepVersions < 1 || $keepVersions > 100) {
            throw new \InvalidArgumentException('At least one and at most 100 derivative versions must be retained.');
        }

        if ($generationLimit < 1 || $generationLimit > 10000) {
            throw new \InvalidArgumentException('Derivative generation cleanup limit must be between 1 and 10000.');
        }
    }
}
