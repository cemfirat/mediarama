<?php

declare(strict_types=1);

namespace Mediarama\Security\Infrastructure\Authentication;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public function __construct(
        private readonly Uuid $id,
        private readonly string $username,
        private ?string $passwordHash,
        private readonly string $status,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function replacePasswordHash(string $newHashedPassword): void
    {
        $this->passwordHash = $newHashedPassword;
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        if (
            !$this->id->equals($user->id)
            || $this->username !== $user->username
            || $this->status !== $user->status
        ) {
            return false;
        }

        return self::passwordsMatch($this->passwordHash, $user->passwordHash);
    }

    public function __serialize(): array
    {
        $data = (array) $this;

        if ($this->passwordHash !== null) {
            $data["\0".self::class."\0passwordHash"] = hash('crc32c', $this->passwordHash);
        }

        return $data;
    }

    private static function passwordsMatch(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if (hash_equals($left, $right)) {
            return true;
        }

        return hash_equals($left, hash('crc32c', $right))
            || hash_equals(hash('crc32c', $left), $right);
    }
}
