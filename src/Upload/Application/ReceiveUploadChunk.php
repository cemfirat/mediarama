<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadChunk;
use Mediarama\Upload\Domain\UploadProblem;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Uid\Uuid;

final readonly class ReceiveUploadChunk
{
    public function __construct(
        private UploadSessionRepository $sessions,
        private ChunkStorage $chunks,
        private UploadPolicy $policy,
    ) {
    }

    /** @param resource $stream */
    public function __invoke(
        Uuid $sessionId,
        Uuid $actingUserId,
        UploadChunk $chunk,
        $stream,
    ): void {
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
            $this->policy->assertChunkSize($chunk->size);

            if ($session->status === UploadStatus::Created) {
                $session->begin();
                $this->sessions->save($session);
            }

            $this->chunks->writeChunk($sessionId, $chunk, $stream);

            if ($session->lastFailureCode !== null) {
                $session->clearFailure();
                $this->sessions->save($session);
            }
        } catch (UploadProblem $problem) {
            if ($problem->failureCode !== null) {
                $session->recordFailure($problem->failureCode);
                $this->sessions->save($session);
            }

            throw $problem;
        }
    }
}
