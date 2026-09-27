<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Upload\Application\AbandonUpload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class AbandonUploadController
{
    public function __construct(
        private AbandonUpload $abandon,
        private CurrentUser $currentUser,
    ) {
    }

    #[Route('/api/uploads/{id}', name: 'upload_abandon', methods: ['DELETE'])]
    public function __invoke(string $id, Request $request): Response
    {
        ($this->abandon)(
            Uuid::fromString($id),
            $this->currentUser->requireUser()->id,
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
