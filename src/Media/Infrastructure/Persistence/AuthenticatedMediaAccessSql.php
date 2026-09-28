<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

final class AuthenticatedMediaAccessSql
{
    public static function predicate(string $alias = 'm'): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid media SQL alias.');
        }

        return <<<SQL
{$alias}.deleted_at IS NULL
AND {$alias}.processing_state = 'ready'
AND (
    {$alias}.owner_id = :user
    OR EXISTS (
        SELECT 1
        FROM collection_media membership
        JOIN actor_visible_collections visible_collection
          ON visible_collection.collection_id = membership.collection_id
        JOIN collections granted_collection
          ON granted_collection.id = membership.collection_id
        LEFT JOIN effective_public_collections public_collection
          ON public_collection.collection_id = membership.collection_id
        WHERE membership.media_id = {$alias}.id
          AND (
              granted_collection.owner_id = :user
              OR (
                  {$alias}.moderation_state <> 'rejected'
                  AND (
                      public_collection.collection_id IS NULL
                      OR {$alias}.moderation_state = 'published'
                  )
              )
          )
    )
)
SQL;
    }
}
