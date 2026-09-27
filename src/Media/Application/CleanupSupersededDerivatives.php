<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;

final readonly class CleanupSupersededDerivatives
{
    public function __construct(
        private DerivativeCleanupRepository $cleanup,
        private MediaStorage $storage,
        private int $graceDays,
        private int $keepNewestVersions,
    ) {
        if ($graceDays < 1) {
            throw new \InvalidArgumentException('Derivative retention grace days must be positive.');
        }

        if ($keepNewestVersions < 1) {
            throw new \InvalidArgumentException('At least the latest derivative version must be retained.');
        }
    }

    public function __invoke(int $versionLimit = 100, int $deleteLimit = 500): DerivativeCleanupReport
    {
        if ($versionLimit < 1 || $deleteLimit < 1) {
            throw new \InvalidArgumentException('Derivative cleanup limits must be positive.');
        }

        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d days', $this->graceDays));
        $staged = $this->cleanup->stageSuperseded(
            $cutoff,
            $this->keepNewestVersions,
            $versionLimit,
        );

        $completed = 0;
        $failed = 0;

        foreach ($this->cleanup->pending($deleteLimit) as $job) {
            if (!$this->isOwnedDerivativeObject($job->storage->disk, $job->storage->key)) {
                ++$failed;
                continue;
            }

            if ($this->cleanup->isReferenced($job->storage)) {
                $this->cleanup->complete($job->id);
                ++$completed;
                continue;
            }

            try {
                if ($this->storage->exists($job->storage)) {
                    $this->storage->delete($job->storage);
                }

                $this->cleanup->complete($job->id);
                ++$completed;
            } catch (\Throwable) {
                // Keep the durable queue row for the next maintenance run.
                ++$failed;
            }
        }

        return new DerivativeCleanupReport($staged, $completed, $failed);
    }

    private function isOwnedDerivativeObject(string $disk, string $key): bool
    {
        if ($disk !== 'media') {
            return false;
        }

        return preg_match(
            '#^derivatives/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/v[1-9][0-9]*/[a-z0-9][a-z0-9_-]*\.[a-z0-9]+$#D',
            $key,
        ) === 1;
    }
}
