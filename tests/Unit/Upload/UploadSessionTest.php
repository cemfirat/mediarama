<?php

declare(strict_types=1);

namespace Mediarama\Tests\Unit\Upload;

use Mediarama\Upload\Domain\UploadFailureCode;
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

    public function testRetryableFailureRemainsInUsableStateAndSuccessfulTransitionClearsIt(): void
    {
        $session = UploadSession::create(Uuid::v7(), null, 'image.jpg', 5000, 'image/jpeg');
        $session->begin();

        $session->recordFailure(UploadFailureCode::ChunkChecksumMismatch);

        self::assertSame(UploadStatus::Uploading, $session->status);
        self::assertSame(UploadFailureCode::ChunkChecksumMismatch, $session->lastFailureCode);
        self::assertSame(UploadFailureStage::Acquisition, $session->lastFailureStage);
        self::assertTrue($session->lastFailureRetryable);
        self::assertNotNull($session->lastFailedAt);

        $session->markUploaded();

        self::assertSame(UploadStatus::Uploaded, $session->status);
        self::assertNull($session->lastFailureCode);
        self::assertNull($session->lastFailureStage);
        self::assertNull($session->lastFailureRetryable);
        self::assertNull($session->lastFailedAt);
    }

    public function testTerminalFailureMovesSessionToFailedAndKeepsSanitizedMetadata(): void
    {
        $session = UploadSession::create(Uuid::v7(), null, 'broken.jpg', 123, 'image/jpeg');
        $session->markUploaded();

        $session->recordFailure(UploadFailureCode::MediaInvalid);

        self::assertSame(UploadStatus::Failed, $session->status);
        self::assertSame(UploadFailureCode::MediaInvalid, $session->lastFailureCode);
        self::assertSame(UploadFailureStage::Validation, $session->lastFailureStage);
        self::assertFalse($session->lastFailureRetryable);
        self::assertSame('The uploaded media is not structurally valid.', $session->lastFailureCode->publicMessage());
    }
}
