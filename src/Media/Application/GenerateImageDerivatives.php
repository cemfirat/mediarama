<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;

final readonly class GenerateImageDerivatives
{
    /** @param list<ImageDerivativeProfile> $profiles */
    public function __construct(
        private MediaDerivativeRepository $derivatives,
        private ImageDerivativeGenerator $generator,
        private MediaStorage $storage,
        private MediaDerivativeRegenerationLock $regenerationLock,
        private array $profiles,
        private int $processingVersion,
        private ?DerivativeCleanupRepository $cleanup = null,
    ) {
        if ($processingVersion < 1) {
            throw new \InvalidArgumentException('Image processing version must be at least 1.');
        }
    }

    public function __invoke(MediaAsset $media): void
    {
        foreach ($this->profiles as $profile) {
            $existing = $this->derivatives->find(
                $media->id,
                'image',
                $profile->name,
                $this->processingVersion,
            );

            if ($existing !== null) {
                continue;
            }

            $this->derivatives->save(
                $this->generator->generate($media, $profile, $this->processingVersion),
            );
        }
    }

    public function regenerate(MediaAsset $media): int
    {
        return $this->regenerationLock->synchronized(
            $media->id,
            'image',
            fn (): int => $this->regenerateLocked($media),
        );
    }

    private function regenerateLocked(MediaAsset $media): int
    {
        $nextVersion = max(
            $this->processingVersion,
            $this->derivatives->latestProcessingVersion($media->id, 'image') + 1,
        );

        /** @var list<MediaDerivative> $generated */
        $generated = [];

        try {
            foreach ($this->profiles as $profile) {
                $generated[] = $this->generator->generate($media, $profile, $nextVersion);
            }

            $this->derivatives->saveAll($generated);
        } catch (\Throwable $error) {
            $this->cleanupGenerated($generated);

            throw $error;
        }

        return $nextVersion;
    }

    /** @param list<MediaDerivative> $generated */
    private function cleanupGenerated(array $generated): void
    {
        foreach ($generated as $derivative) {
            $cleanupJob = null;

            if ($this->cleanup !== null) {
                try {
                    // Persist cleanup debt before touching storage. If the process dies
                    // after the delete but before acknowledgement, the retry sees a
                    // missing object and safely completes the same idempotent job.
                    $cleanupJob = $this->cleanup->enqueueOrphanedDerivative($derivative);
                } catch (\Throwable) {
                    // Preserve the primary generation/persistence failure and still try
                    // the immediate best-effort delete below.
                }
            }

            try {
                $this->storage->delete($derivative->storage);

                if ($cleanupJob !== null) {
                    $this->cleanup?->completeStorageJob($cleanupJob->id);
                }
            } catch (\Throwable) {
                // A successfully persisted cleanup job remains retryable. If queue
                // persistence itself was unavailable, preserve the primary failure.
            }
        }
    }
}
