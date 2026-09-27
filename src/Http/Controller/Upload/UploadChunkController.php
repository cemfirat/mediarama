<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\ReceiveUploadChunk;
use Mediarama\Upload\Domain\UploadChunk;
use Mediarama\Upload\Domain\UploadProblem;
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
        $userId = $this->currentUser->requireUser()->id;
        $size = (int) $request->headers->get('Content-Length', '0');
        $offset = (int) $request->headers->get('Upload-Offset', '0');
        $checksum = strtolower((string) $request->headers->get('Upload-Checksum-SHA256', ''));

        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to read upload request body.');
        }

        try {
            try {
                $chunk = new UploadChunk($index, $offset, $size, $checksum);
            } catch (\InvalidArgumentException $error) {
                throw UploadProblem::invalidChunkMetadata();
            }

            ($this->receive)(
                Uuid::fromString($id),
                $userId,
                $chunk,
                $stream,
            );
        } finally {
            fclose($stream);
        }

        return new JsonResponse(['accepted' => true], 202);
    }
}
