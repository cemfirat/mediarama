<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Upload\Domain\UploadProblem;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Uid\Uuid;

final readonly class AbandonUpload
{
    public function __construct(
        private UploadSessionRepository $sessions,
        private ChunkStorage $chunks,
        private MediaStorage $storage,
        private UploadFinalizationRepository $finalizations,
        private UploadFinalizationCriticalSection $criticalSection,
    ) {
    }

    public function __invoke(Uuid $sessionId, Uuid $actingUserId): void
    {
        try {
            $this->criticalSection->run(
                $sessionId,
                function () use ($sessionId, $actingUserId): void {
                    $session = $this->sessions->get($sessionId);

                    if (!$session->userId->equals($actingUserId)) {
                        throw UploadProblem::sessionNotFound();
                    }

                    if (
                        !in_array($session->status, [UploadStatus::Uploaded, UploadStatus::Failed], true)
                        || $this->finalizations->findMediaId($sessionId) !== null
                    ) {
                        throw UploadProblem::invalidState();
                    }

                    $this->chunks->deleteSessionChunks($sessionId);

                    $temporary = new StorageObjectId('media', $session->temporaryStorageKey);
                    if ($this->storage->exists($temporary)) {
                        $this->storage->delete($temporary);
                    }

                    // A previous failed finalization may already have promoted
                    // the deterministic original without committing MediaAsset
                    // state. No finalization mapping exists here, so it is safe
                    // to remove that orphan while holding the session row lock.
                    $permanent = new StorageObjectId(
                        'media',
                        sprintf('originals/%s/source', $sessionId->toRfc4122()),
                    );
                    if ($this->storage->exists($permanent)) {
                        $this->storage->delete($permanent);
                    }

                    // The reservation FK cascades from upload_sessions. Failed
                    // sessions may already have released it explicitly; either
                    // way the database operation remains idempotent.
                    $this->sessions->delete($session);
                },
            );
        } catch (UploadProblem $problem) {
            throw $problem;
        } catch (\Throwable $error) {
            throw UploadProblem::abandonUnavailable($error);
        }
    }
}
