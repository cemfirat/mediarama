<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\ChunkStorage;
use Mediarama\Upload\Application\UploadSessionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

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
        $session = $this->sessions->get(UploadRequestId::parse($id));
        $actor = $this->currentUser->requireUser()->id;

        if (!$session->userId->equals($actor)) {
            throw \Mediarama\Upload\Application\UploadProblem::request(
                'upload_not_found',
                'Upload session is not accessible to the acting user.',
            );
        }

        return new JsonResponse([
            'id' => $session->id->toRfc4122(),
            'status' => $session->status->value,
            'expected_size' => $session->expectedSize,
            'failure' => $session->lastFailure === null ? null : [
                'code' => $session->lastFailure->code,
                'stage' => $session->lastFailure->stage->value,
                'retryable' => $session->lastFailure->retryable,
                'failed_at' => $session->lastFailure->failedAt->format(DATE_ATOM),
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
