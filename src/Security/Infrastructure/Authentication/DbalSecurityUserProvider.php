<?php

declare(strict_types=1);

namespace Mediarama\Security\Infrastructure\Authentication;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class DbalSecurityUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT id, username, password_hash, status
FROM users
WHERE username = :identifier
LIMIT 1
SQL,
            ['identifier' => $identifier],
        );

        if ($row === false) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return new SecurityUser(
            Uuid::fromString((string) $row['id']),
            (string) $row['username'],
            $row['password_hash'] !== null ? (string) $row['password_hash'] : null,
            (string) $row['status'],
        );
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(sprintf('Unsupported security user "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(sprintf('Unsupported security user "%s".', $user::class));
        }

        $this->connection->executeStatement(
            'UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            [
                'password_hash' => $newHashedPassword,
                'id' => $user->id()->toRfc4122(),
            ],
        );

        $user->replacePasswordHash($newHashedPassword);
    }
}
