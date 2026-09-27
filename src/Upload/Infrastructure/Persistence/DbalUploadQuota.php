<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Upload\Application\UploadQuota;
use Mediarama\Upload\Application\UploadQuotaExceeded;
use Symfony\Component\Uid\Uuid;

final readonly class DbalUploadQuota implements UploadQuota
{
    public function __construct(
        private Connection $connection,
        private int $defaultQuotaBytes,
    ) {
        if ($defaultQuotaBytes < 0) {
            throw new \InvalidArgumentException('Default upload quota must not be negative.');
        }
    }

    public function reserve(
        Uuid $sessionId,
        Uuid $userId,
        int $bytes,
        callable $persistSession,
    ): void {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Reserved upload bytes must not be negative.');
        }

        $this->connection->transactional(function () use (
            $sessionId,
            $userId,
            $bytes,
            $persistSession,
        ): void {
            $user = $this->connection->fetchOne(
                'SELECT id FROM users WHERE id = :user_id FOR UPDATE',
                ['user_id' => $userId->toRfc4122()],
            );

            if ($user === false) {
                throw new \DomainException('Upload quota user not found.');
            }

            // Read committed + reserved bytes in one PostgreSQL snapshot.
            // Finalization swaps reservation state for a MediaAsset atomically,
            // so this observes either the pre- or post-conversion state.
            $usage = $this->connection->fetchAssociative(
                <<<'SQL'
SELECT
    COALESCE((
        SELECT SUM(m.byte_size)
        FROM media_assets m
        WHERE m.owner_id = :user_id
    ), 0) AS committed_bytes,
    COALESCE((
        SELECT SUM(r.reserved_bytes)
        FROM upload_quota_reservations r
        WHERE r.user_id = :user_id
    ), 0) AS reserved_bytes
SQL,
                ['user_id' => $userId->toRfc4122()],
            );

            if ($usage === false) {
                throw new \RuntimeException('Unable to calculate upload quota usage.');
            }

            $committed = (int) $usage['committed_bytes'];
            $reserved = (int) $usage['reserved_bytes'];
            $limit = $this->effectiveLimit($userId);

            if ($limit > 0 && $committed + $reserved + $bytes > $limit) {
                throw new UploadQuotaExceeded($limit, $committed, $reserved, $bytes);
            }

            $this->connection->executeStatement(
                <<<'SQL'
INSERT INTO upload_quota_reservations (
    upload_session_id, user_id, reserved_bytes, created_at
) VALUES (
    :session_id, :user_id, :reserved_bytes, NOW()
)
SQL,
                [
                    'session_id' => $sessionId->toRfc4122(),
                    'user_id' => $userId->toRfc4122(),
                    'reserved_bytes' => $bytes,
                ],
            );

            $persistSession();
        });
    }

    public function commit(Uuid $sessionId): void
    {
        $this->connection->delete(
            'upload_quota_reservations',
            ['upload_session_id' => $sessionId->toRfc4122()],
        );
    }

    private function effectiveLimit(Uuid $userId): int
    {
        $userLimit = $this->connection->fetchOne(
            'SELECT limit_bytes FROM user_storage_quotas WHERE user_id = :user_id',
            ['user_id' => $userId->toRfc4122()],
        );

        if ($userLimit !== false && $userLimit !== null) {
            return (int) $userLimit;
        }

        $groupLimit = $this->connection->fetchOne(
            <<<'SQL'
SELECT CASE
    WHEN COUNT(*) = 0 THEN NULL
    WHEN BOOL_OR(q.limit_bytes = 0) THEN 0
    ELSE MAX(q.limit_bytes)
END
FROM user_groups ug
JOIN group_storage_quotas q ON q.group_id = ug.group_id
WHERE ug.user_id = :user_id
SQL,
            ['user_id' => $userId->toRfc4122()],
        );

        if ($groupLimit !== false && $groupLimit !== null) {
            return (int) $groupLimit;
        }

        return $this->defaultQuotaBytes;
    }
}
