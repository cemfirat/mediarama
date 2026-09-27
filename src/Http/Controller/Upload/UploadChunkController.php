<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\ReceiveUploadChunk;
use Mediarama\Upload\Application\UploadProblem;
use Mediarama\Upload\Domain\UploadChunk;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class UploadChunkController
{
    public function __construct(private ReceiveUploadChunk $receive, private CurrentUser $currentUser)
    {
    }

    #[Route('/api/uploads/{id}/chunks/{index}', name: 'upload_chunk', methods: ['PUT'])]
    public function __invoke(string $id, int $index, Request $request): JsonResponse
    {
        $size = (int) $request->headers->get('Content-Length', '0');
        $offset = (int) $request->headers->get('Upload-Offset', '0');
        $checksum = strtolower((string) $request->headers->get('Upload-Checksum-SHA256', ''));

        try {
            $sessionId = Uuid::fromString($id);
            $chunk = new UploadChunk($index, $offset, $size, $checksum);
        } catch (\Throwable) {
            throw UploadProblem::request(
                'invalid_upload_request',
                'Upload chunk request metadata is invalid.',
            );
        }

        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw UploadProblem::retryable(
                'upload_temporarily_unavailable',
                \Mediarama\Upload\Domain\UploadFailureStage::Acquisition,
                'Upload request body cannot be read.',
            );
        }

        try {
            ($this->receive)(
                $sessionId,
                $this->currentUser->requireUser()->id,
                $chunk,
                $stream,
            );
        } finally {
            fclose($stream);
        }

        return new JsonResponse(['accepted' => true], 202);
    }
}
