<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\ChunkStorage;
use Mediarama\Upload\Application\UploadSessionRepository;
use Mediarama\Upload\Domain\UploadProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class UploadStatusController
{
    public function __construct(
        private UploadSessionRepository $sessions,
        private ChunkStorage $chunks,
        private CurrentUser $currentUser,
    ) {
    }

    #[Route('/api/uploads/{id}', name: 'upload_status', methods: ['GET'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $session = $this->sessions->get(Uuid::fromString($id));
        $actor = $this->currentUser->requireUser()->id;

        if (!$session->userId->equals($actor)) {
            throw UploadProblem::sessionNotFound();
        }

        return new JsonResponse([
            'id' => $session->id->toRfc4122(),
            'status' => $session->status->value,
            'expected_size' => $session->expectedSize,
            'failure' => $session->lastFailureCode === null ? null : [
                'code' => $session->lastFailureCode->value,
                'stage' => $session->lastFailureStage?->value,
                'retryable' => $session->lastFailureRetryable,
                'at' => $session->lastFailedAt?->format(DATE_ATOM),
                'message' => $session->lastFailureCode->publicMessage(),
            ],
            'chunks' => array_map(static fn ($chunk): array => [
                'index' => $chunk->index,
                'offset' => $chunk->offset,
                'size' => $chunk->size,
                'checksum_sha256' => $chunk->checksumSha256,
            ], $this->chunks->listChunks($session->id)),
        ]);
    }
}
