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
        private DerivativeCleanupRepository $cleanup,
        private MediaDerivativeRegenerationLock $regenerationLock,
        private array $profiles,
        private int $processingVersion,
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
            $cleanupFailure = $this->cleanupGenerated($generated);

            if ($cleanupFailure !== null) {
                throw new \RuntimeException(
                    'Derivative generation failed and orphan cleanup could not be recorded.',
                    previous: $error,
                );
            }

            throw $error;
        }

        return $nextVersion;
    }

    /** @param list<MediaDerivative> $generated */
    private function cleanupGenerated(array $generated): ?\Throwable
    {
        $queueFailure = null;

        foreach ($generated as $derivative) {
            try {
                $this->storage->delete($derivative->storage);
            } catch (\Throwable) {
                try {
                    $this->cleanup->enqueueOrphan($derivative);
                } catch (\Throwable $error) {
                    $queueFailure ??= $error;
                }
            }
        }

        return $queueFailure;
    }
}
