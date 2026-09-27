<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Security;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Security\Infrastructure\Http\UploadCsrfSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CsrfTokenController
{
    public function __construct(
        private CurrentUser $currentUser,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/api/auth/csrf', name: 'auth_csrf', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $this->currentUser->requireUser();

        return new JsonResponse([
            'upload_token' => $this->csrf->getToken(UploadCsrfSubscriber::TOKEN_ID)->getValue(),
        ], 200, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
