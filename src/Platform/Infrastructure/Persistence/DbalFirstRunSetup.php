<?php

declare(strict_types=1);

namespace Mediarama\Platform\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Platform\Application\FirstRunSetup;
use Mediarama\Platform\Application\FirstRunSetupResult;
use Mediarama\Platform\Application\SetupUnavailableException;
use Mediarama\Platform\Application\SetupValidationException;
use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\PlatformSettings;
use Mediarama\Platform\Domain\SetupCompletionMethod;
use Mediarama\Platform\Domain\SetupState;
use Mediarama\Platform\Domain\SetupStatus;
use Mediarama\Security\Infrastructure\Authentication\SecurityUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DbalFirstRunSetup implements FirstRunSetup
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function state(): SetupState
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT setup_status, setup_completed_at, setup_completed_by, setup_completed_via
FROM platform_settings
WHERE id = 1
SQL
        );

        if ($row === false) {
            throw new \RuntimeException('Platform settings are not initialized.');
        }

        return new SetupState(
            SetupStatus::from((string) $row['setup_status']),
            $row['setup_completed_at'] !== null
                ? new DateTimeImmutable((string) $row['setup_completed_at'])
                : null,
            $row['setup_completed_by'] !== null
                ? Uuid::fromString((string) $row['setup_completed_by'])
                : null,
            $row['setup_completed_via'] !== null
                ? SetupCompletionMethod::from((string) $row['setup_completed_via'])
                : null,
        );
    }

    public function complete(
        string $username,
        string $password,
        ?string $email,
        DeploymentProfile $profile,
        SetupCompletionMethod $completedVia,
        bool $allowInactiveRecovery = false,
    ): FirstRunSetupResult {
        $username = trim($username);
        $email = $email !== null ? trim($email) : null;
        $email = $email === '' ? null : $email;

        $this->validateInput($username, $password, $email);

        if (!in_array($completedVia, [SetupCompletionMethod::Browser, SetupCompletionMethod::Cli], true)) {
            throw new \InvalidArgumentException('Unsupported first-run setup completion method.');
        }

        return $this->connection->transactional(function (Connection $connection) use (
            $username,
            $password,
            $email,
            $profile,
            $completedVia,
            $allowInactiveRecovery,
        ): FirstRunSetupResult {
            $settings = $connection->fetchAssociative(
                <<<'SQL'
SELECT setup_status, deployment_profile
FROM platform_settings
WHERE id = 1
FOR UPDATE
SQL
            );

            if ($settings === false) {
                throw new \RuntimeException('Platform settings are not initialized.');
            }

            if ((string) $settings['setup_status'] !== SetupStatus::Pending->value) {
                throw new SetupUnavailableException('Setup has already been completed.');
            }

            $administratorRows = $connection->fetchAllAssociative(
                <<<'SQL'
SELECT DISTINCT u.id, u.username, u.email, u.status, u.created_at
FROM users u
INNER JOIN user_groups ug ON ug.user_id = u.id
INNER JOIN group_permissions gp ON gp.group_id = ug.group_id
WHERE gp.permission_key = 'system.admin'
ORDER BY u.created_at ASC, u.id ASC
SQL
            );

            foreach ($administratorRows as $administrator) {
                if ((string) $administrator['status'] !== 'active') {
                    continue;
                }

                $administratorId = Uuid::fromString((string) $administrator['id']);
                $currentProfile = DeploymentProfile::from((string) $settings['deployment_profile']);

                $this->markExistingAdministratorCompleted(
                    $connection,
                    $administratorId,
                );

                return new FirstRunSetupResult(
                    $administratorId,
                    (string) $administrator['username'],
                    $currentProfile,
                    false,
                    true,
                );
            }

            if ($administratorRows !== []) {
                foreach ($administratorRows as $administrator) {
                    if ((string) $administrator['username'] !== $username) {
                        continue;
                    }

                    $status = (string) $administrator['status'];
                    $claimable = $status === 'password_reset_required'
                        || ($allowInactiveRecovery && $status === 'inactive');

                    if (!$claimable) {
                        break;
                    }

                    $administratorId = Uuid::fromString((string) $administrator['id']);
                    $this->ensureEmailAvailable($connection, $email, $administratorId);
                    $passwordHash = $this->hashPassword($administratorId, $username, $password);

                    $connection->executeStatement(
                        <<<'SQL'
UPDATE users
SET password_hash = :password_hash,
    status = 'active',
    email = CASE WHEN email IS NULL THEN :email ELSE email END,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :id
SQL,
                        [
                            'password_hash' => $passwordHash,
                            'email' => $email,
                            'id' => $administratorId->toRfc4122(),
                        ],
                    );

                    $this->finishSetup(
                        $connection,
                        $administratorId,
                        $profile,
                        $completedVia,
                    );

                    return new FirstRunSetupResult(
                        $administratorId,
                        $username,
                        $profile,
                        true,
                        true,
                    );
                }

                throw new SetupUnavailableException(
                    'An administrator identity already exists. Use that administrator username or the server-side recovery command; setup will not create a duplicate administrator.',
                );
            }

            $this->ensureNewIdentityAvailable($connection, $username, $email);

            $administratorId = Uuid::v7();
            $passwordHash = $this->hashPassword($administratorId, $username, $password);
            $now = (new DateTimeImmutable())->format(DATE_ATOM);

            $connection->insert('users', [
                'id' => $administratorId->toRfc4122(),
                'username' => $username,
                'email' => $email,
                'password_hash' => $passwordHash,
                'display_name' => $username,
                'status' => 'active',
                'locale' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'last_login_at' => null,
            ]);

            $groupId = $this->administratorGroup($connection, $now);

            $connection->executeStatement(
                <<<'SQL'
INSERT INTO permissions (permission_key, description)
VALUES ('system.admin', 'Administrative access to Mediarama.')
ON CONFLICT (permission_key) DO NOTHING
SQL
            );

            $connection->executeStatement(
                <<<'SQL'
INSERT INTO group_permissions (group_id, permission_key)
VALUES (:group_id, 'system.admin')
ON CONFLICT DO NOTHING
SQL,
                ['group_id' => $groupId->toRfc4122()],
            );

            $connection->insert(
                'user_groups',
                [
                    'user_id' => $administratorId->toRfc4122(),
                    'group_id' => $groupId->toRfc4122(),
                    'is_primary' => true,
                    'created_at' => $now,
                ],
                ['is_primary' => ParameterType::BOOLEAN],
            );

            $this->finishSetup(
                $connection,
                $administratorId,
                $profile,
                $completedVia,
            );

            return new FirstRunSetupResult(
                $administratorId,
                $username,
                $profile,
                true,
                false,
            );
        });
    }

    private function validateInput(string $username, string $password, ?string $email): void
    {
        if (
            strlen($username) < 3
            || strlen($username) > 120
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]*$/D', $username) !== 1
        ) {
            throw new SetupValidationException(
                'Username must be 3-120 characters and use letters, numbers, dot, underscore, @ or hyphen.',
            );
        }

        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new SetupValidationException(
                'Administrator password must contain at least 12 characters.',
            );
        }

        if (
            $email !== null
            && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)
        ) {
            throw new SetupValidationException('Enter a valid email address or leave it empty.');
        }
    }

    private function ensureNewIdentityAvailable(
        Connection $connection,
        string $username,
        ?string $email,
    ): void {
        $exists = (bool) $connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM users WHERE username = :username)',
            ['username' => $username],
        );

        if ($exists) {
            throw new SetupValidationException('Unable to use those administrator account details.');
        }

        $this->ensureEmailAvailable($connection, $email, null);
    }

    private function ensureEmailAvailable(
        Connection $connection,
        ?string $email,
        ?Uuid $currentUser,
    ): void {
        if ($email === null) {
            return;
        }

        $sql = 'SELECT EXISTS (SELECT 1 FROM users WHERE email = :email';
        $parameters = ['email' => $email];

        if ($currentUser !== null) {
            $sql .= ' AND id <> :current_user';
            $parameters['current_user'] = $currentUser->toRfc4122();
        }

        $sql .= ')';

        if ((bool) $connection->fetchOne($sql, $parameters)) {
            throw new SetupValidationException('Unable to use those administrator account details.');
        }
    }

    private function hashPassword(Uuid $id, string $username, string $password): string
    {
        $securityUser = new SecurityUser($id, $username, null, 'active');

        return $this->passwordHasher->hashPassword($securityUser, $password);
    }

    private function administratorGroup(Connection $connection, string $now): Uuid
    {
        $existing = $connection->fetchOne(
            "SELECT id FROM groups WHERE slug = 'mediarama-administrators'"
        );

        if ($existing !== false) {
            $groupId = Uuid::fromString((string) $existing);
            $connection->executeStatement(
                'UPDATE groups SET is_system = TRUE, updated_at = :updated WHERE id = :id',
                ['updated' => $now, 'id' => $groupId->toRfc4122()],
            );

            return $groupId;
        }

        $groupId = Uuid::v7();
        $connection->insert(
            'groups',
            [
                'id' => $groupId->toRfc4122(),
                'slug' => 'mediarama-administrators',
                'name' => 'Mediarama Administrators',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['is_system' => ParameterType::BOOLEAN],
        );

        return $groupId;
    }

    private function finishSetup(
        Connection $connection,
        Uuid $administratorId,
        DeploymentProfile $profile,
        SetupCompletionMethod $completedVia,
    ): void {
        $profileSettings = PlatformSettings::forProfile($profile);

        $updated = $connection->executeStatement(
            <<<'SQL'
UPDATE platform_settings
SET deployment_profile = :profile,
    public_publishing_enabled = :publishing,
    search_index_default = :index_default,
    setup_status = 'completed',
    setup_completed_at = CURRENT_TIMESTAMP,
    setup_completed_by = :administrator,
    setup_completed_via = :completed_via,
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1
  AND setup_status = 'pending'
SQL,
            [
                'profile' => $profileSettings->deploymentProfile->value,
                'publishing' => $profileSettings->publicPublishingEnabled,
                'index_default' => $profileSettings->searchIndexDefault->value,
                'administrator' => $administratorId->toRfc4122(),
                'completed_via' => $completedVia->value,
            ],
            ['publishing' => ParameterType::BOOLEAN],
        );

        if ($updated !== 1) {
            throw new SetupUnavailableException('Setup is no longer available.');
        }
    }

    private function markExistingAdministratorCompleted(
        Connection $connection,
        Uuid $administratorId,
    ): void {
        $updated = $connection->executeStatement(
            <<<'SQL'
UPDATE platform_settings
SET setup_status = 'completed',
    setup_completed_at = CURRENT_TIMESTAMP,
    setup_completed_by = :administrator,
    setup_completed_via = 'existing_admin',
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1
  AND setup_status = 'pending'
SQL,
            ['administrator' => $administratorId->toRfc4122()],
        );

        if ($updated !== 1) {
            throw new SetupUnavailableException('Setup is no longer available.');
        }
    }
}
