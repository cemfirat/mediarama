<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;

final readonly class GenerateVideoDerivatives
{
    public function __construct(
        private MediaDerivativeRepository $derivatives,
        private VideoPosterGenerator $posterGenerator,
        private VideoPlaybackGenerator $playbackGenerator,
        private MediaStorage $storage,
        private DerivativeCleanupRepository $cleanup,
        private MediaDerivativeRegenerationLock $regenerationLock,
        private VideoPosterProfile $posterProfile,
        private VideoPlaybackProfile $playbackProfile,
        private int $processingVersion,
    ) {
        if ($processingVersion < 1) {
            throw new \InvalidArgumentException('Video processing version must be at least 1.');
        }
    }

    public function __invoke(MediaAsset $media): void
    {
        /** @var list<MediaDerivative> $generated */
        $generated = [];

        try {
            if ($this->derivatives->find(
                $media->id,
                'video',
                $this->posterProfile->name,
                $this->processingVersion,
            ) === null) {
                $generated[] = $this->posterGenerator->generate(
                    $media,
                    $this->posterProfile,
                    $this->processingVersion,
                );
            }

            if ($this->derivatives->find(
                $media->id,
                'video',
                $this->playbackProfile->name,
                $this->processingVersion,
            ) === null) {
                $generated[] = $this->playbackGenerator->generate(
                    $media,
                    $this->playbackProfile,
                    $this->processingVersion,
                );
            }

            if ($generated !== []) {
                $this->derivatives->saveAll($generated);
            }
        } catch (\Throwable $error) {
            $cleanupFailure = $this->cleanupGenerated($generated);

            if ($cleanupFailure !== null) {
                throw new \RuntimeException(
                    'Video derivative generation failed and orphan cleanup could not be recorded.',
                    previous: $error,
                );
            }

            throw $error;
        }
    }

    public function regenerate(MediaAsset $media): int
    {
        return $this->regenerationLock->synchronized(
            $media->id,
            'video',
            fn (): int => $this->regenerateLocked($media),
        );
    }

    private function regenerateLocked(MediaAsset $media): int
    {
        $nextVersion = max(
            $this->processingVersion,
            $this->derivatives->latestProcessingVersion($media->id, 'video') + 1,
        );

        /** @var list<MediaDerivative> $generated */
        $generated = [];

        try {
            $generated[] = $this->posterGenerator->generate(
                $media,
                $this->posterProfile,
                $nextVersion,
            );
            $generated[] = $this->playbackGenerator->generate(
                $media,
                $this->playbackProfile,
                $nextVersion,
            );

            $this->derivatives->saveAll($generated);
        } catch (\Throwable $error) {
            $cleanupFailure = $this->cleanupGenerated($generated);

            if ($cleanupFailure !== null) {
                throw new \RuntimeException(
                    'Video derivative regeneration failed and orphan cleanup could not be recorded.',
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
