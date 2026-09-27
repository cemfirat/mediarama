<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Upload;

use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Upload\Domain\UploadFailureStage;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UploadSessionTest extends TestCase
{
    public function testHappyPathStateTransitions(): void
    {
        $session = UploadSession::create(Uuid::v7(), null, 'image.jpg', 5000, 'image/jpeg');

        self::assertSame(UploadStatus::Created, $session->status);
        self::assertStringStartsWith('temporary/', $session->temporaryStorageKey);

        $session->begin();
        $session->markUploaded();
        $session->beginFinalization();
        $session->complete();

        self::assertSame(UploadStatus::Completed, $session->status);
    }

    public function testRetryableFailureCanBeClearedWithoutChangingLifecycleState(): void
    {
        $session = UploadSession::create(Uuid::v7(), null, 'image.jpg', 5000, 'image/jpeg');
        $session->begin();

        $session->recordFailure(
            'chunk_checksum_mismatch',
            UploadFailureStage::Acquisition,
            true,
        );

        self::assertSame(UploadStatus::Uploading, $session->status);
        self::assertTrue($session->lastFailure?->retryable ?? false);

        $session->clearFailure();

        self::assertSame(UploadStatus::Uploading, $session->status);
        self::assertNull($session->lastFailure);
    }

    public function testDownstreamMediaFailureDoesNotBecomeUploadFailure(): void
    {
        $userId = Uuid::v7();
        $session = UploadSession::create($userId, null, 'image.jpg', 5000, 'image/jpeg');
        $session->markUploaded();
        $session->beginFinalization();
        $session->complete();

        $asset = MediaAsset::createWithId(
            $session->id,
            $userId,
            new StorageObjectId('media', 'originals/'.$session->id->toRfc4122().'/source'),
            'image.jpg',
            'image/jpeg',
            MediaType::Image,
            5000,
            str_repeat('a', 64),
        );
        $asset->markFailed();

        self::assertSame('failed', $asset->processingState->value);
        self::assertSame(UploadStatus::Completed, $session->status);
        self::assertNull($session->lastFailure);
    }
}
