<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use DateTimeImmutable;
use Mediarama\Media\Application\DerivativeCleanupRepository;
use Mediarama\Media\Application\GenerateVideoDerivatives;
use Mediarama\Media\Application\MediaDerivativeRepository;
use Mediarama\Media\Application\MediaDerivativeRegenerationLock;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Application\VideoPlaybackGenerator;
use Mediarama\Media\Application\VideoPlaybackProfile;
use Mediarama\Media\Application\VideoPosterGenerator;
use Mediarama\Media\Application\VideoPosterProfile;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class GenerateVideoDerivativesTest extends TestCase
{
    public function testRegenerationPublishesPosterAndPlaybackAsOneVersion(): void
    {
        $asset = $this->videoAsset();

        $repository = new class implements MediaDerivativeRepository {
            /** @var list<MediaDerivative> */
            public array $saved = [];

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Video regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                $this->saved = $derivatives;
            }

            public function find(Uuid $mediaId, string $kind, string $profile, int $processingVersion): ?MediaDerivative
            {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                if ($kind !== 'video') {
                    throw new \LogicException('Video regeneration must use the video derivative kind.');
                }

                return 4;
            }
        };

        $poster = new class implements VideoPosterGenerator {
            /** @var list<int> */
            public array $versions = [];

            public function generate(
                MediaAsset $media,
                VideoPosterProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $this->versions[] = $processingVersion;

                return GenerateVideoDerivativesTest::derivative(
                    $media,
                    $profile->name,
                    $processingVersion,
                    'image/jpeg',
                    'jpg',
                );
            }
        };

        $playback = new class implements VideoPlaybackGenerator {
            /** @var list<int> */
            public array $versions = [];

            public function generate(
                MediaAsset $media,
                VideoPlaybackProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $this->versions[] = $processingVersion;

                return GenerateVideoDerivativesTest::derivative(
                    $media,
                    $profile->name,
                    $processingVersion,
                    'video/mp4',
                    'mp4',
                );
            }
        };

        $storage = $this->storage();
        $service = $this->service(
            $repository,
            $poster,
            $playback,
            $storage,
            $this->cleanup(),
        );

        self::assertSame(5, $service->regenerate($asset));
        self::assertSame([5], $poster->versions);
        self::assertSame([5], $playback->versions);
        self::assertCount(2, $repository->saved);
        self::assertSame(
            ['poster', 'browser_mp4'],
            array_map(static fn (MediaDerivative $d): string => $d->profile, $repository->saved),
        );
        self::assertSame(
            [5, 5],
            array_map(static fn (MediaDerivative $d): int => $d->processingVersion, $repository->saved),
        );
        self::assertSame([], $storage->deleted);
    }

    public function testPartialGenerationFailureDeletesAlreadyGeneratedArtifacts(): void
    {
        $asset = $this->videoAsset();

        $repository = $this->repository(3);
        $poster = new class implements VideoPosterGenerator {
            public function generate(
                MediaAsset $media,
                VideoPosterProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                return GenerateVideoDerivativesTest::derivative(
                    $media,
                    $profile->name,
                    $processingVersion,
                    'image/jpeg',
                    'jpg',
                );
            }
        };
        $playback = new class implements VideoPlaybackGenerator {
            public function generate(
                MediaAsset $media,
                VideoPlaybackProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                throw new \RuntimeException('Synthetic video transcode failure.');
            }
        };

        $storage = $this->storage();
        $cleanup = $this->cleanup();
        $service = $this->service($repository, $poster, $playback, $storage, $cleanup);

        try {
            $service->regenerate($asset);
            self::fail('Expected playback generation to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic video transcode failure.', $error->getMessage());
        }

        self::assertCount(1, $storage->deleted);
        self::assertStringContainsString('/v4/poster.jpg', $storage->deleted[0]->key);
        self::assertSame([], $cleanup->orphaned);
    }

    public function testFailedPhysicalCleanupQueuesKnownVideoDerivative(): void
    {
        $asset = $this->videoAsset();

        $repository = $this->repository(1);
        $poster = new class implements VideoPosterGenerator {
            public function generate(
                MediaAsset $media,
                VideoPosterProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                return GenerateVideoDerivativesTest::derivative(
                    $media,
                    $profile->name,
                    $processingVersion,
                    'image/jpeg',
                    'jpg',
                );
            }
        };
        $playback = new class implements VideoPlaybackGenerator {
            public function generate(
                MediaAsset $media,
                VideoPlaybackProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                throw new \RuntimeException('Synthetic video transcode failure.');
            }
        };

        $storage = $this->storage(failDelete: true);
        $cleanup = $this->cleanup();
        $service = $this->service($repository, $poster, $playback, $storage, $cleanup);

        try {
            $service->regenerate($asset);
            self::fail('Expected playback generation to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic video transcode failure.', $error->getMessage());
        }

        self::assertCount(1, $cleanup->orphaned);
        self::assertSame('video', $cleanup->orphaned[0]->kind);
        self::assertSame('poster', $cleanup->orphaned[0]->profile);
        self::assertSame(2, $cleanup->orphaned[0]->processingVersion);
    }

    private function service(
        MediaDerivativeRepository $repository,
        VideoPosterGenerator $poster,
        VideoPlaybackGenerator $playback,
        MediaStorage $storage,
        DerivativeCleanupRepository $cleanup,
    ): GenerateVideoDerivatives {
        return new GenerateVideoDerivatives(
            $repository,
            $poster,
            $playback,
            $storage,
            $cleanup,
            new class implements MediaDerivativeRegenerationLock {
                public function synchronized(Uuid $mediaId, string $kind, callable $operation): mixed
                {
                    if ($kind !== 'video') {
                        throw new \LogicException('Video regeneration lock must use the video derivative kind.');
                    }

                    return $operation();
                }
            },
            new VideoPosterProfile('poster', 1280, 1280),
            new VideoPlaybackProfile(
                'browser_mp4',
                1920,
                1080,
                'libx264',
                23,
                'medium',
                'aac',
                '128k',
            ),
            1,
        );
    }

    private function videoAsset(): MediaAsset
    {
        return MediaAsset::create(
            null,
            new StorageObjectId('media', 'originals/video/source'),
            'private-source.mp4',
            'video/mp4',
            MediaType::Video,
            123,
            str_repeat('a', 64),
        );
    }

    private function repository(int $latest): MediaDerivativeRepository
    {
        return new class($latest) implements MediaDerivativeRepository {
            public function __construct(private readonly int $latest)
            {
            }

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Video regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                throw new \LogicException('Persistence must not run after generation failure.');
            }

            public function find(Uuid $mediaId, string $kind, string $profile, int $processingVersion): ?MediaDerivative
            {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return $this->latest;
            }
        };
    }

    private function cleanup(): DerivativeCleanupRepository
    {
        return new class implements DerivativeCleanupRepository {
            /** @var list<MediaDerivative> */
            public array $orphaned = [];

            public function stageSuperseded(
                DateTimeImmutable $cutoff,
                int $keepNewestVersions,
                int $limitVersions,
            ): int {
                return 0;
            }

            public function pending(int $limit): array
            {
                return [];
            }

            public function complete(int $jobId): void
            {
            }

            public function isReferenced(StorageObjectId $storage): bool
            {
                return false;
            }

            public function enqueueOrphan(MediaDerivative $derivative): void
            {
                $this->orphaned[] = $derivative;
            }
        };
    }

    private function storage(bool $failDelete = false): MediaStorage
    {
        return new class($failDelete) implements MediaStorage {
            /** @var list<StorageObjectId> */
            public array $deleted = [];

            public function __construct(private readonly bool $failDelete)
            {
            }

            public function write(StorageObjectId $id, $stream, ?string $contentType = null): StoredObject
            {
                throw new \LogicException('Storage write is owned by the generator.');
            }

            public function read(StorageObjectId $id)
            {
                throw new \LogicException('Storage read is not used in this unit test.');
            }

            public function exists(StorageObjectId $id): bool
            {
                return false;
            }

            public function stat(StorageObjectId $id): StoredObject
            {
                throw new \LogicException('Storage stat is not used in this unit test.');
            }

            public function delete(StorageObjectId $id): void
            {
                if ($this->failDelete) {
                    throw new \RuntimeException('Synthetic delete failure.');
                }

                $this->deleted[] = $id;
            }

            public function promote(StorageObjectId $temporary, StorageObjectId $permanent): StoredObject
            {
                throw new \LogicException('Storage promote is not used in this unit test.');
            }

            public function publicUrl(StorageObjectId $id): ?string
            {
                return null;
            }

            public function temporaryUrl(StorageObjectId $id, DateTimeImmutable $expiresAt): ?string
            {
                return null;
            }
        };
    }

    public static function derivative(
        MediaAsset $media,
        string $profile,
        int $version,
        string $mime,
        string $extension,
    ): MediaDerivative {
        $now = new DateTimeImmutable();

        return new MediaDerivative(
            Uuid::v7(),
            $media->id,
            'video',
            $profile,
            $version,
            new StorageObjectId(
                'media',
                sprintf(
                    'derivatives/%s/v%d/%s.%s',
                    $media->id->toRfc4122(),
                    $version,
                    $profile,
                    $extension,
                ),
            ),
            $mime,
            100,
            320,
            180,
            $profile === 'browser_mp4' ? 1000 : null,
            [],
            $now,
            $now,
        );
    }
}
