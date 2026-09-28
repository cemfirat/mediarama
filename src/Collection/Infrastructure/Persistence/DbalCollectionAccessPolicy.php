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
            CollectionAccessSql::authenticatedVisibleCollectionsCte().<<<'SQL'

SELECT COUNT(*)
FROM actor_visible_collections
WHERE collection_id = :collection
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
      AND collection.mode = 'manual'
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
