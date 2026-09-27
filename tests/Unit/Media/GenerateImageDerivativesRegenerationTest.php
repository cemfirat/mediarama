<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Media;

use DateTimeImmutable;
use Mediarama\Media\Application\GenerateImageDerivatives;
use Mediarama\Media\Application\ImageDerivativeGenerator;
use Mediarama\Media\Application\ImageDerivativeProfile;
use Mediarama\Media\Application\MediaDerivativeRepository;
use Mediarama\Media\Application\MediaDerivativeRegenerationLock;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class GenerateImageDerivativesRegenerationTest extends TestCase
{
    public function testRegenerationCreatesOneAtomicNextVersionForAllProfiles(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            /** @var list<MediaDerivative> */
            public array $savedAll = [];

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                $this->savedAll = $derivatives;
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 4;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            /** @var list<int> */
            public array $versions = [];

            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $this->versions[] = $processingVersion;
                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId(
                        'media',
                        sprintf(
                            'derivatives/%s/v%d/%s.webp',
                            $media->id->toRfc4122(),
                            $processingVersion,
                            $profile->name,
                        ),
                    ),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $storage = $this->trackingStorage();

        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $storage,
            $this->immediateLock(),
            [
                new ImageDerivativeProfile('thumbnail', 480, 480),
                new ImageDerivativeProfile('preview', 1600, 1600),
                new ImageDerivativeProfile('large', 2560, 2560),
            ],
            1,
        );

        $version = $service->regenerate($asset);

        self::assertSame(5, $version);
        self::assertSame([5, 5, 5], $generator->versions);
        self::assertCount(3, $repository->savedAll);
        self::assertSame(
            ['thumbnail', 'preview', 'large'],
            array_map(static fn (MediaDerivative $d): string => $d->profile, $repository->savedAll),
        );
        self::assertSame(
            [5, 5, 5],
            array_map(static fn (MediaDerivative $d): int => $d->processingVersion, $repository->savedAll),
        );
        self::assertSame([], $storage->deleted);
    }

    public function testConfiguredVersionIsTheRegenerationFloor(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            /** @var list<MediaDerivative> */
            public array $savedAll = [];

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                $this->savedAll = $derivatives;
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 0;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId('media', 'derivatives/test/v'.$processingVersion.'/'.$profile->name.'.webp'),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $this->trackingStorage(),
            $this->immediateLock(),
            [new ImageDerivativeProfile('thumbnail', 480, 480)],
            3,
        );

        self::assertSame(3, $service->regenerate($asset));
        self::assertSame(3, $repository->savedAll[0]->processingVersion);
    }

    public function testFailedRegenerationCleansGeneratedArtifactsAndPublishesNothing(): void
    {
        $asset = $this->imageAsset();

        $repository = new class implements MediaDerivativeRepository {
            public bool $saveAllCalled = false;

            public function save(MediaDerivative $derivative): void
            {
                throw new \LogicException('Versioned regeneration must use batch persistence.');
            }

            public function saveAll(array $derivatives): void
            {
                $this->saveAllCalled = true;
            }

            public function find(
                Uuid $mediaId,
                string $kind,
                string $profile,
                int $processingVersion,
            ): ?MediaDerivative {
                return null;
            }

            public function latestProcessingVersion(Uuid $mediaId, string $kind): int
            {
                return 2;
            }
        };

        $generator = new class implements ImageDerivativeGenerator {
            private int $calls = 0;

            public function generate(
                MediaAsset $media,
                ImageDerivativeProfile $profile,
                int $processingVersion,
            ): MediaDerivative {
                ++$this->calls;

                if ($this->calls === 2) {
                    throw new \RuntimeException('Synthetic second-profile failure.');
                }

                $now = new DateTimeImmutable();

                return new MediaDerivative(
                    Uuid::v7(),
                    $media->id,
                    'image',
                    $profile->name,
                    $processingVersion,
                    new StorageObjectId(
                        'media',
                        sprintf(
                            'derivatives/%s/v%d/%s.webp',
                            $media->id->toRfc4122(),
                            $processingVersion,
                            $profile->name,
                        ),
                    ),
                    'image/webp',
                    100,
                    64,
                    64,
                    null,
                    [],
                    $now,
                    $now,
                );
            }
        };

        $storage = $this->trackingStorage();

        $service = new GenerateImageDerivatives(
            $repository,
            $generator,
            $storage,
            $this->immediateLock(),
            [
                new ImageDerivativeProfile('thumbnail', 480, 480),
                new ImageDerivativeProfile('preview', 1600, 1600),
            ],
            1,
        );

        try {
            $service->regenerate($asset);
            self::fail('Expected second profile generation to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic second-profile failure.', $error->getMessage());
        }

        self::assertFalse($repository->saveAllCalled);
        self::assertCount(1, $storage->deleted);
        self::assertStringContainsString('/v3/thumbnail.webp', $storage->deleted[0]->key);
    }

    private function imageAsset(): MediaAsset
    {
        return MediaAsset::create(
            null,
            new StorageObjectId('media', 'originals/test.jpg'),
            'test.jpg',
            'image/jpeg',
            MediaType::Image,
            123,
            str_repeat('a', 64),
        );
    }


    private function immediateLock(): MediaDerivativeRegenerationLock
    {
        return new class implements MediaDerivativeRegenerationLock {
            public function synchronized(Uuid $mediaId, string $kind, callable $operation): mixed
            {
                return $operation();
            }
        };
    }

    private function trackingStorage(): MediaStorage
    {
        return new class implements MediaStorage {
            /** @var list<StorageObjectId> */
            public array $deleted = [];

            public function write(StorageObjectId $id, $stream, ?string $contentType = null): StoredObject
            {
                throw new \LogicException('Storage write is owned by the derivative generator in this unit test.');
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
}
