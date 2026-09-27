<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

final class CollectionAccessSql
{
    public static function authenticatedVisibleCollectionsCte(): string
    {
        $rootAccess = self::nodeAccessPredicate('root');
        $childAccess = self::nodeAccessPredicate('child');

        return <<<SQL
WITH RECURSIVE actor_visible_collections AS (
    SELECT root.id AS collection_id
    FROM collections root
    WHERE root.parent_id IS NULL
      AND {$rootAccess}

    UNION ALL

    SELECT child.id AS collection_id
    FROM collections child
    JOIN actor_visible_collections visible_parent
      ON visible_parent.collection_id = child.parent_id
    WHERE {$childAccess}
)
SQL;
    }

    private static function nodeAccessPredicate(string $alias): string
    {
        return <<<SQL
{$alias}.deleted_at IS NULL
AND (
    {$alias}.owner_id = :user
    OR (
        {$alias}.password_protected = FALSE
        AND {$alias}.password_reset_required = FALSE
        AND (
            {$alias}.visibility IN ('public', 'authenticated')
            OR (
                {$alias}.visibility = 'restricted'
                AND EXISTS (
                    SELECT 1
                    FROM collection_access acl
                    WHERE acl.collection_id = {$alias}.id
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
            )
        )
    )
)
SQL;
    }
}
