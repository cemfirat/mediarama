<?php

declare(strict_types=1);

namespace Mediarama\Security\Infrastructure\Authentication;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof SecurityUser || $user->status() !== 'active') {
            throw new CustomUserMessageAccountStatusException('This account is not available for sign-in.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
