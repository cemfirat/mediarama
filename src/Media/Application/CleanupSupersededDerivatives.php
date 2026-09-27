<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeImmutable;

final readonly class CleanupSupersededDerivatives
{
    public function __construct(
        private DerivativeCleanupRepository $cleanup,
        private MediaStorage $storage,
        private MediaDerivativeRegenerationLock $regenerationLock,
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
            if (
                !$this->isOwnedDerivativeObject($job->storage->disk, $job->storage->key)
                || $job->mediaId === null
                || $job->kind === null
            ) {
                ++$failed;
                continue;
            }

            try {
                $this->regenerationLock->synchronized(
                    $job->mediaId,
                    $job->kind,
                    function () use ($job): void {
                        // A failed generation can later reuse the same deterministic
                        // key. Re-check under the regeneration lock so queued cleanup
                        // can never delete an object that has become live again.
                        if ($this->cleanup->isReferenced($job->storage)) {
                            $this->cleanup->complete($job->id);

                            return;
                        }

                        if ($this->storage->exists($job->storage)) {
                            $this->storage->delete($job->storage);
                        }

                        $this->cleanup->complete($job->id);
                    },
                );
                ++$completed;
            } catch (\Throwable) {
                // Keep the durable queue row for the next maintenance run. This also
                // handles a concurrent regeneration holding the media/kind lock.
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
