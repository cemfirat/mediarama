<?php

declare(strict_types=1);

namespace Mediarama\Security\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Mediarama\Security\Application\SystemAdministrationPolicy;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSystemAdministrationPolicy implements SystemAdministrationPolicy
{
    public function __construct(private Connection $connection)
    {
    }

    public function canAdminister(Uuid $userId): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM user_groups ug
    INNER JOIN group_permissions gp ON gp.group_id = ug.group_id
    WHERE ug.user_id = :user
      AND gp.permission_key = 'system.admin'
)
SQL,
            ['user' => $userId->toRfc4122()],
        );
    }
}
