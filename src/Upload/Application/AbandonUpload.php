<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Uid\Uuid;

final readonly class AbandonUpload
{
    public function __construct(
        private UploadSessionRepository $sessions,
        private ChunkStorage $chunks,
        private MediaStorage $storage,
    ) {
    }

    public function __invoke(Uuid $sessionId, Uuid $actingUserId): void
    {
        $session = $this->sessions->get($sessionId);

        if (!$session->userId->equals($actingUserId)) {
            throw UploadProblem::request(
                'upload_not_found',
                'Upload session is not accessible to the acting user.',
            );
        }

        if (in_array($session->status, [UploadStatus::Finalizing, UploadStatus::Completed], true)) {
            throw UploadProblem::request(
                'upload_state_conflict',
                'Finalizing or completed uploads cannot be abandoned.',
            );
        }

        $this->chunks->deleteSessionChunks($session->id);

        $temporary = new StorageObjectId('media', $session->temporaryStorageKey);
        if ($this->storage->exists($temporary)) {
            $this->storage->delete($temporary);
        }

        if ($session->status === UploadStatus::Failed) {
            $permanent = new StorageObjectId(
                'media',
                sprintf('originals/%s/source', $session->id->toRfc4122()),
            );
            if ($this->storage->exists($permanent)) {
                $this->storage->delete($permanent);
            }
        }

        // upload_quota_reservations cascades from upload_sessions. A previously
        // released terminal reservation therefore remains safe and idempotent.
        $this->sessions->delete($session);
    }
}
