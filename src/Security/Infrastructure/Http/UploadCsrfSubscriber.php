<?php

declare(strict_types=1);

namespace Mediarama\Security\Infrastructure\Http;

use Mediarama\Security\Infrastructure\Authentication\SecurityUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AsEventListener(event: 'kernel.request', priority: 0)]
final readonly class UploadCsrfSubscriber
{
    public const TOKEN_ID = 'upload_write';

    public function __construct(
        private TokenStorageInterface $tokens,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/uploads') || $request->isMethodSafe()) {
            return;
        }

        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof SecurityUser) {
            return;
        }

        $value = $request->headers->get('X-CSRF-Token');
        if (!is_string($value) || !$this->csrf->isTokenValid(new CsrfToken(self::TOKEN_ID, $value))) {
            $event->setResponse(new JsonResponse(
                ['error' => 'invalid_csrf_token'],
                Response::HTTP_FORBIDDEN,
                ['Cache-Control' => 'no-store'],
            ));
        }
    }
}
