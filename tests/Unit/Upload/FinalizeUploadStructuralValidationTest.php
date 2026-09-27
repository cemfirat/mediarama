<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Upload;

use DateTimeImmutable;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Application\ValidateStoredMediaStructure;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Upload\Application\ContentInspector;
use Mediarama\Upload\Application\FinalizeUpload;
use Mediarama\Upload\Application\InspectedContent;
use Mediarama\Upload\Application\UploadContentPolicy;
use Mediarama\Upload\Application\UploadDestinationAuthorizer;
use Mediarama\Upload\Application\UploadFinalizationCriticalSection;
use Mediarama\Upload\Application\UploadFinalizationRepository;
use Mediarama\Upload\Application\UploadSessionRepository;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class FinalizeUploadStructuralValidationTest extends TestCase
{
    public function testStructuralFailureHappensBeforeFinalizationSideEffects(): void
    {
        $userId = Uuid::v7();
        $session = UploadSession::create($userId, null, 'broken.jpg', 123, 'image/jpeg');
        $session->markUploaded();

        $sessions = new class($session) implements UploadSessionRepository {
            public int $saves = 0;

            public function __construct(private UploadSession $session)
            {
            }

            public function save(UploadSession $session): void
            {
                ++$this->saves;
                $this->session = $session;
            }

            public function get(Uuid $id): UploadSession
            {
                return $this->session;
            }
        };

        $media = new class implements MediaAssetRepository {
            public int $saves = 0;

            public function save(MediaAsset $media): void
            {
                ++$this->saves;
            }

            public function get(Uuid $id): MediaAsset
            {
                throw new \LogicException('No finalized media should exist.');
            }
        };

        $storage = new class implements MediaStorage {
            public int $promotions = 0;

            public function write(StorageObjectId $id, $stream, ?string $contentType = null): StoredObject
            {
                throw new \LogicException('Write is not expected.');
            }

            public function read(StorageObjectId $id)
            {
                throw new \LogicException('Read is not expected.');
            }

            public function exists(StorageObjectId $id): bool
            {
                return true;
            }

            public function stat(StorageObjectId $id): StoredObject
            {
                throw new \LogicException('Stat is not expected.');
            }

            public function delete(StorageObjectId $id): void
            {
                throw new \LogicException('Delete is not expected.');
            }

            public function promote(StorageObjectId $temporary, StorageObjectId $permanent): StoredObject
            {
                ++$this->promotions;
                throw new \LogicException('Promotion must not happen after structural validation failure.');
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

        $inspector = new class implements ContentInspector {
            public function inspect(StorageObjectId $object): InspectedContent
            {
                return new InspectedContent('image/jpeg', MediaType::Image, str_repeat('a', 64), 123);
            }
        };

        $structure = new class implements ValidateStoredMediaStructure {
            public int $calls = 0;

            public function __invoke(StorageObjectId $object, MediaType $mediaType): void
            {
                ++$this->calls;
                throw new \DomainException('Uploaded image failed structural validation.');
            }
        };

        $authorizer = new class implements UploadDestinationAuthorizer {
            public function assertCanUpload(Uuid $userId, ?Uuid $collectionId): void
            {
            }
        };

        $finalizations = new class implements UploadFinalizationRepository {
            public int $remembers = 0;

            public function findMediaId(Uuid $sessionId): ?Uuid
            {
                return null;
            }

            public function remember(Uuid $sessionId, Uuid $mediaId): void
            {
                ++$this->remembers;
            }
        };

        $criticalSection = new class implements UploadFinalizationCriticalSection {
            public int $calls = 0;

            public function run(Uuid $sessionId, callable $operation): mixed
            {
                ++$this->calls;

                return $operation();
            }
        };

        $bus = new class implements MessageBusInterface {
            public int $dispatches = 0;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->dispatches;

                return new Envelope($message, $stamps);
            }
        };

        $finalize = new FinalizeUpload(
            $sessions,
            $media,
            $storage,
            $inspector,
            new UploadContentPolicy(['image/jpeg']),
            $structure,
            $authorizer,
            $finalizations,
            $criticalSection,
            $bus,
        );

        try {
            $finalize($session->id, $userId);
            self::fail('Expected structural validation to reject the upload.');
        } catch (\DomainException $error) {
            self::assertSame('Uploaded image failed structural validation.', $error->getMessage());
        }

        self::assertSame(1, $structure->calls);
        self::assertSame(UploadStatus::Uploaded, $session->status);
        self::assertSame(0, $sessions->saves);
        self::assertSame(0, $storage->promotions);
        self::assertSame(0, $media->saves);
        self::assertSame(0, $finalizations->remembers);
        self::assertSame(0, $criticalSection->calls);
        self::assertSame(0, $bus->dispatches);
    }
}
