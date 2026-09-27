<?php

declare(strict_types=1);

namespace Mediarama\Security\Infrastructure\Http;

use Mediarama\Security\Application\AuthenticatedUser;
use Mediarama\Security\Application\CurrentUser;
use Mediarama\Security\Infrastructure\Authentication\SecurityUser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RequestCurrentUser implements CurrentUser
{
    public function __construct(
        private RequestStack $requests,
        private TokenStorageInterface $tokens,
        private string $appEnvironment,
    ) {
    }

    public function requireUser(): AuthenticatedUser
    {
        $securityUser = $this->tokens->getToken()?->getUser();

        if ($securityUser instanceof SecurityUser) {
            return new AuthenticatedUser($securityUser->id());
        }

        if ($this->appEnvironment === 'dev' || $this->appEnvironment === 'test') {
            $request = $this->requests->getCurrentRequest();
            $value = $request?->attributes->get('_mediarama_user_id');

            if (is_string($value) && $value !== '') {
                return new AuthenticatedUser(Uuid::fromString($value));
            }
        }

        throw new \DomainException('Authentication is required.');
    }
}
