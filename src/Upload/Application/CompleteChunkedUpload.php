<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadFailureStage;
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
            throw UploadProblem::request(
                'upload_not_found',
                'Upload session is not accessible to the acting user.',
            );
        }

        if ($session->isExpired()) {
            throw UploadProblem::request('upload_expired', 'Upload session has expired.');
        }

        try {
            $this->chunks->assemble(
                $sessionId,
                $session->expectedSize,
                $session->temporaryStorageKey,
            );
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
                UploadFailureStage::Assembly,
                'Upload assembly is temporarily unavailable.',
                previous: $error,
            );
            $session->recordFailure(
                $problem->publicCode,
                UploadFailureStage::Assembly,
                true,
            );
            $this->sessions->save($session);

            throw $problem;
        }

        $session->markUploaded();
        $this->sessions->save($session);

        $this->chunks->deleteSessionChunks($sessionId);
    }
}
