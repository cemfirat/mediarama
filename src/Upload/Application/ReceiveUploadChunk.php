<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadChunk;
use Mediarama\Upload\Domain\UploadFailureStage;
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
            throw UploadProblem::request(
                'upload_not_found',
                'Upload session is not accessible to the acting user.',
            );
        }

        if ($session->isExpired()) {
            throw UploadProblem::request('upload_expired', 'Upload session has expired.');
        }

        if (!in_array($session->status, [UploadStatus::Created, UploadStatus::Uploading], true)) {
            throw UploadProblem::request(
                'upload_state_conflict',
                'Upload session is not accepting chunks.',
            );
        }

        try {
            $this->policy->assertChunkSize($chunk->size);

            if ($session->status === UploadStatus::Created) {
                $session->begin();
                $this->sessions->save($session);
            }

            $this->chunks->writeChunk($sessionId, $chunk, $stream);
        } catch (UploadProblem $error) {
            if ($error->failureStage !== null) {
                $session->recordFailure(
                    $error->publicCode,
                    $error->failureStage,
                    $error->retryable,
                );
                $this->sessions->save($session);
            }

            throw $error;
        } catch (\RuntimeException $error) {
            $problem = UploadProblem::retryable(
                'upload_temporarily_unavailable',
                UploadFailureStage::Acquisition,
                'Chunk storage is temporarily unavailable.',
                previous: $error,
            );
            $session->recordFailure(
                $problem->publicCode,
                UploadFailureStage::Acquisition,
                true,
            );
            $this->sessions->save($session);

            throw $problem;
        }

        if ($session->lastFailure !== null) {
            $session->clearFailure();
            $this->sessions->save($session);
        }
    }
}
