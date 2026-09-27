<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\CollectionAccessPolicy;
use Symfony\Component\Uid\Uuid;

final readonly class DbalCollectionAccessPolicy implements CollectionAccessPolicy
{
    public function __construct(private Connection $connection)
    {
    }

    public function canView(?Uuid $userId, Uuid $collectionId): bool
    {
        if ($userId === null) {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM effective_public_collections WHERE collection_id = :collection',
                ['collection' => $collectionId->toRfc4122()],
            ) === 1;
        }

        $allowed = $this->connection->fetchOne(
            <<<'SQL'
WITH RECURSIVE lineage AS (
    SELECT
        c.id,
        c.parent_id,
        c.owner_id,
        c.visibility,
        c.deleted_at,
        c.password_protected,
        c.password_reset_required,
        ARRAY[c.id]::uuid[] AS path,
        FALSE AS cycle
    FROM collections c
    WHERE c.id = :collection

    UNION ALL

    SELECT
        parent.id,
        parent.parent_id,
        parent.owner_id,
        parent.visibility,
        parent.deleted_at,
        parent.password_protected,
        parent.password_reset_required,
        child.path || parent.id,
        parent.id = ANY(child.path) AS cycle
    FROM collections parent
    JOIN lineage child ON child.parent_id = parent.id
    WHERE child.cycle = FALSE
),
evaluated AS (
    SELECT
        node.id,
        CASE
            WHEN node.cycle THEN FALSE
            WHEN node.deleted_at IS NOT NULL THEN FALSE
            WHEN node.owner_id = :user THEN TRUE
            WHEN node.password_protected OR node.password_reset_required THEN FALSE
            WHEN node.visibility = 'public' THEN TRUE
            WHEN node.visibility = 'authenticated' THEN TRUE
            WHEN node.visibility = 'private' THEN FALSE
            WHEN node.visibility = 'restricted' THEN EXISTS (
                SELECT 1
                FROM collection_access acl
                WHERE acl.collection_id = node.id
                  AND acl.capability = 'collection.view'
                  AND acl.effect = 'allow'
                  AND (
                      acl.user_id = :user
                      OR acl.group_id IN (
                          SELECT membership.group_id
                          FROM user_groups membership
                          WHERE membership.user_id = :user
                      )
                  )
            )
            ELSE FALSE
        END AS can_view
    FROM lineage node
)
SELECT CASE
    WHEN NOT EXISTS (SELECT 1 FROM lineage) THEN 0
    WHEN EXISTS (SELECT 1 FROM evaluated WHERE can_view = FALSE) THEN 0
    ELSE 1
END
SQL,
            [
                'collection' => $collectionId->toRfc4122(),
                'user' => $userId->toRfc4122(),
            ],
        );

        return (int) $allowed === 1;
    }

    public function canAddMedia(Uuid $userId, Uuid $collectionId): bool
    {
        $allowed = $this->connection->fetchOne(
            <<<'SQL'
SELECT CASE WHEN EXISTS (
    SELECT 1
    FROM collections collection
    WHERE collection.id = :collection
      AND collection.deleted_at IS NULL
      AND (
          collection.owner_id = :user
          OR EXISTS (
              SELECT 1
              FROM collection_access acl
              WHERE acl.collection_id = collection.id
                AND acl.capability = 'collection.media.add'
                AND acl.effect = 'allow'
                AND (
                    acl.user_id = :user
                    OR acl.group_id IN (
                        SELECT membership.group_id
                        FROM user_groups membership
                        WHERE membership.user_id = :user
                    )
                )
          )
      )
) THEN 1 ELSE 0 END
SQL,
            [
                'collection' => $collectionId->toRfc4122(),
                'user' => $userId->toRfc4122(),
            ],
        );

        return (int) $allowed === 1;
    }
}
