<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\FinalizeUpload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FinalizeUploadController
{
    public function __construct(private FinalizeUpload $finalize, private CurrentUser $currentUser)
    {
    }

    #[Route('/api/uploads/{id}/finalize', name: 'upload_finalize', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $media = ($this->finalize)(
            UploadRequestId::parse($id),
            $this->currentUser->requireUser()->id,
        );

        return new JsonResponse([
            'media_id' => $media->id->toRfc4122(),
            'processing_state' => $media->processingState->value,
        ], 202);
    }
}
