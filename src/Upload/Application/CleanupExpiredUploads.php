<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use DateTimeImmutable;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Upload\Domain\UploadStatus;

final readonly class CleanupExpiredUploads
{
    public function __construct(
        private ExpiredUploadSessionRepository $sessions,
        private ChunkStorage $chunks,
        private MediaStorage $storage,
    ) {
    }

    public function __invoke(int $limit = 100): int
    {
        $expired = $this->sessions->findExpired(new DateTimeImmutable(), $limit);

        foreach ($expired as $session) {
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

            // The reservation FK cascades from upload_sessions, so database
            // deletion releases quota atomically and exactly once.
            $this->sessions->delete($session);
        }

        return count($expired);
    }
}
