<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Security\Infrastructure\Authorization\DbalSystemAdministrationPolicy;
use Symfony\Component\Uid\Uuid;

function requireSystemAdmin(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);

$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$policy = new DbalSystemAdministrationPolicy($db);

$admin = Uuid::v7();
$ordinary = Uuid::v7();
$inactive = Uuid::v7();
$group = Uuid::v7();
$now = (new DateTimeImmutable())->format(DATE_ATOM);

try {
    foreach ([
        [$admin, 'active'],
        [$ordinary, 'active'],
        [$inactive, 'inactive'],
    ] as [$id, $status]) {
        $db->insert('users', [
            'id' => $id->toRfc4122(),
            'username' => 'system-admin-policy-'.$id->toRfc4122(),
            'email' => null,
            'password_hash' => null,
            'display_name' => null,
            'status' => $status,
            'locale' => 'en',
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => null,
        ]);
    }

    $db->insert(
        'groups',
        [
            'id' => $group->toRfc4122(),
            'slug' => 'system-admin-policy-'.$group->toRfc4122(),
            'name' => 'System Administration Policy',
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        ['is_system' => ParameterType::BOOLEAN],
    );

    $db->insert('group_permissions', [
        'group_id' => $group->toRfc4122(),
        'permission_key' => 'system.admin',
    ]);

    foreach ([$admin, $inactive] as $userId) {
        $db->insert(
            'user_groups',
            [
                'user_id' => $userId->toRfc4122(),
                'group_id' => $group->toRfc4122(),
                'is_primary' => true,
                'created_at' => $now,
            ],
            ['is_primary' => ParameterType::BOOLEAN],
        );
    }

    requireSystemAdmin(
        $policy->canAdminister($admin),
        'active user with system.admin group permission can administer',
    );
    requireSystemAdmin(
        !$policy->canAdminister($ordinary),
        'ordinary active user without system.admin cannot administer',
    );
    requireSystemAdmin(
        !$policy->canAdminister($inactive),
        'inactive user is denied even when its group has system.admin',
    );
} finally {
    $db->executeStatement(
        'DELETE FROM users WHERE id IN (:admin, :ordinary, :inactive)',
        [
            'admin' => $admin->toRfc4122(),
            'ordinary' => $ordinary->toRfc4122(),
            'inactive' => $inactive->toRfc4122(),
        ],
    );
    $db->delete('groups', ['id' => $group->toRfc4122()]);
    $db->close();
}
