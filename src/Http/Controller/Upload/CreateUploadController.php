<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\CreateUploadSession;
use Mediarama\Upload\Application\UploadProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class CreateUploadController
{
    public function __construct(private CreateUploadSession $create, private CurrentUser $currentUser)
    {
    }

    #[Route('/api/uploads', name: 'upload_create', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable $error) {
            throw UploadProblem::request(
                'invalid_upload_request',
                'Upload create request body is not valid JSON.',
            );
        }

        $filename = $payload['filename'] ?? null;
        $size = $payload['size'] ?? null;
        $mime = $payload['mime'] ?? null;
        $collectionValue = $payload['collection_id'] ?? null;

        if (
            !is_string($filename)
            || trim($filename) === ''
            || !is_int($size)
            || ($mime !== null && !is_string($mime))
            || ($collectionValue !== null && !is_string($collectionValue))
        ) {
            throw UploadProblem::request(
                'invalid_upload_request',
                'Upload create request contains invalid fields.',
            );
        }

        $collectionId = null;
        if ($collectionValue !== null) {
            try {
                $collectionId = Uuid::fromString($collectionValue);
            } catch (\Throwable) {
                throw UploadProblem::request(
                    'invalid_upload_request',
                    'Upload collection identifier is invalid.',
                );
            }
        }

        $session = ($this->create)(
            $this->currentUser->requireUser()->id,
            $collectionId,
            $filename,
            $size,
            $mime,
        );

        return new JsonResponse([
            'id' => $session->id->toRfc4122(),
            'status' => $session->status->value,
            'expires_at' => $session->expiresAt->format(DATE_ATOM),
        ], 201);
    }
}
