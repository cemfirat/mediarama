<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadProblem;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Uid\Uuid;

final readonly class CompleteChunkedUpload
{
    public function __construct(
        private UploadSessionRepository $sessions,
        private ChunkStorage $chunks,
    ) {
    }

    public function __invoke(Uuid $sessionId, Uuid $actingUserId): void
    {
        $session = $this->sessions->get($sessionId);

        if (!$session->userId->equals($actingUserId)) {
            throw UploadProblem::sessionNotFound();
        }

        if ($session->isExpired()) {
            throw UploadProblem::expired();
        }

        if (!in_array($session->status, [UploadStatus::Created, UploadStatus::Uploading], true)) {
            throw UploadProblem::invalidState();
        }

        try {
            $this->chunks->assemble(
                $sessionId,
                $session->expectedSize,
                $session->temporaryStorageKey,
            );
        } catch (UploadProblem $problem) {
            if ($problem->failureCode !== null) {
                $session->recordFailure($problem->failureCode);
                $this->sessions->save($session);
            }

            throw $problem;
        }

        $session->markUploaded();
        $this->sessions->save($session);

        $this->chunks->deleteSessionChunks($sessionId);
    }
}
