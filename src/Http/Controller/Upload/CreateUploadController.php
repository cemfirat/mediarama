<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\CreateUploadSession;
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
        $payload = $request->toArray();

        $userId = $this->currentUser->requireUser()->id;
        $collectionId = isset($payload['collection_id']) && $payload['collection_id'] !== null
            ? Uuid::fromString((string) $payload['collection_id'])
            : null;

        $session = ($this->create)(
            $userId,
            $collectionId,
            (string) ($payload['filename'] ?? ''),
            (int) ($payload['size'] ?? -1),
            isset($payload['mime']) ? (string) $payload['mime'] : null,
        );

        return new JsonResponse([
            'id' => $session->id->toRfc4122(),
            'status' => $session->status->value,
            'expires_at' => $session->expiresAt->format(DATE_ATOM),
        ], 201);
    }
}
