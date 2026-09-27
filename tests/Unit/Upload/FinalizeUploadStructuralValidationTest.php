<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Upload;

use DateTimeImmutable;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\MediaValidationRejected;
use Mediarama\Media\Application\MediaValidationUnavailable;
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
use Mediarama\Upload\Application\UploadProblem;
use Mediarama\Upload\Application\UploadQuota;
use Mediarama\Upload\Application\UploadSessionRepository;
use Mediarama\Upload\Domain\UploadFailureStage;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class FinalizeUploadStructuralValidationTest extends TestCase
{
    public function testRejectedMediaIsTerminalAndReleasesQuotaBeforeSideEffects(): void
    {
        $this->assertValidationFailure(
            new MediaValidationRejected('Decoder rejected malformed media.'),
            expectedCode: 'invalid_media',
            expectedRetryable: false,
            expectedTerminal: true,
            expectedStatus: UploadStatus::Failed,
            expectedQuotaReleases: 1,
        );
    }

    public function testUnavailableValidationIsRetryableAndKeepsQuotaReservation(): void
    {
        $this->assertValidationFailure(
            new MediaValidationUnavailable('Validation process timed out.'),
            expectedCode: 'upload_temporarily_unavailable',
            expectedRetryable: true,
            expectedTerminal: false,
            expectedStatus: UploadStatus::Uploaded,
            expectedQuotaReleases: 0,
        );
    }

    public function testUnexpectedValidationRuntimeFailureFailsSafeAsRetryable(): void
    {
        $this->assertValidationFailure(
            new \RuntimeException('Unexpected validator transport failure.'),
            expectedCode: 'upload_temporarily_unavailable',
            expectedRetryable: true,
            expectedTerminal: false,
            expectedStatus: UploadStatus::Uploaded,
            expectedQuotaReleases: 0,
        );
    }

    private function assertValidationFailure(
        \Throwable $validationFailure,
        string $expectedCode,
        bool $expectedRetryable,
        bool $expectedTerminal,
        UploadStatus $expectedStatus,
        int $expectedQuotaReleases,
    ): void {
        $userId = Uuid::v7();
        $session = UploadSession::create($userId, null, 'fixture.jpg', 123, 'image/jpeg');
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

            public function delete(UploadSession $session): void
            {
                throw new \LogicException('Delete is not expected.');
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
                throw new \LogicException('Promotion must not happen after validation failure.');
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
                return new InspectedContent(
                    'image/jpeg',
                    MediaType::Image,
                    str_repeat('a', 64),
                    123,
                );
            }
        };

        $structure = new class($validationFailure) implements ValidateStoredMediaStructure {
            public int $calls = 0;

            public function __construct(private \Throwable $failure)
            {
            }

            public function __invoke(StorageObjectId $object, MediaType $mediaType): void
            {
                ++$this->calls;
                throw $this->failure;
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

        $quota = new class implements UploadQuota {
            public int $commits = 0;
            public int $releases = 0;

            public function reserve(
                Uuid $sessionId,
                Uuid $userId,
                int $bytes,
                callable $persistSession,
            ): void {
                $persistSession();
            }

            public function commit(Uuid $sessionId): void
            {
                ++$this->commits;
            }

            public function release(Uuid $sessionId): void
            {
                ++$this->releases;
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
            $quota,
            $criticalSection,
            $bus,
        );

        try {
            $finalize($session->id, $userId);
            self::fail('Expected structural validation to stop finalization.');
        } catch (UploadProblem $error) {
            self::assertSame($expectedCode, $error->publicCode);
            self::assertSame($expectedRetryable, $error->retryable);
            self::assertSame($expectedTerminal, $error->terminal);
            self::assertSame(UploadFailureStage::Finalization, $error->failureStage);
            self::assertSame($validationFailure, $error->getPrevious());
        }

        self::assertSame(1, $structure->calls);
        self::assertSame($expectedStatus, $session->status);
        self::assertSame($expectedCode, $session->lastFailure?->code);
        self::assertSame($expectedRetryable, $session->lastFailure?->retryable);
        self::assertSame(1, $sessions->saves);
        self::assertSame(0, $storage->promotions);
        self::assertSame(0, $media->saves);
        self::assertSame(0, $finalizations->remembers);
        self::assertSame(0, $quota->commits);
        self::assertSame($expectedQuotaReleases, $quota->releases);
        self::assertSame(1, $criticalSection->calls);
        self::assertSame(0, $bus->dispatches);
    }
}
