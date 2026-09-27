<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Media\Application\PublicMediaSearch;
use Mediarama\Media\Application\PublicMediaSearchCriteria;
use Mediarama\Media\Application\PublicMediaSearchResult;
use Symfony\Component\Uid\Uuid;

final readonly class DbalPublicMediaSearch implements PublicMediaSearch
{
    public function __construct(private Connection $connection)
    {
    }

    public function search(PublicMediaSearchCriteria $criteria): array
    {
        $where = [
            "m.deleted_at IS NULL",
            "m.processing_state = 'ready'",
            "m.moderation_state = 'published'",
            <<<'SQL'
EXISTS (
    SELECT 1
    FROM collection_media public_membership
    JOIN effective_public_collections public_collection
      ON public_collection.collection_id = public_membership.collection_id
    WHERE public_membership.media_id = m.id
)
SQL,
        ];
        $params = [];
        $types = [];

        if ($criteria->text !== null && trim($criteria->text) !== '') {
            $where[] = <<<'SQL'
to_tsvector(
    'simple',
    coalesce(m.title, '') || ' ' || coalesce(m.description, '')
) @@ websearch_to_tsquery('simple', :text)
SQL;
            $params['text'] = trim($criteria->text);
        }

        $params['limit'] = $criteria->limit;
        $params['offset'] = $criteria->offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                 m.id,
                 m.mime_type,
                 m.media_type,
                 m.title,
                 m.description,
                 thumbnail.processing_version AS thumbnail_version,
                 preview.processing_version AS preview_version
             FROM media_assets m
             LEFT JOIN LATERAL (
                 SELECT d.processing_version
                 FROM media_derivatives d
                 WHERE d.media_id = m.id
                   AND d.kind = \'image\'
                   AND d.profile = \'thumbnail\'
                 ORDER BY d.processing_version DESC
                 LIMIT 1
             ) thumbnail ON TRUE
             LEFT JOIN LATERAL (
                 SELECT d.processing_version
                 FROM media_derivatives d
                 WHERE d.media_id = m.id
                   AND d.kind = \'image\'
                   AND d.profile = \'preview\'
                 ORDER BY d.processing_version DESC
                 LIMIT 1
             ) preview ON TRUE
             WHERE '.implode(' AND ', $where).'
             ORDER BY m.created_at DESC, m.id
             LIMIT :limit OFFSET :offset',
            $params,
            $types,
        );

        return array_map(static fn (array $row): PublicMediaSearchResult => new PublicMediaSearchResult(
            Uuid::fromString((string) $row['id']),
            (string) $row['mime_type'],
            (string) $row['media_type'],
            $row['title'] !== null ? (string) $row['title'] : null,
            $row['description'] !== null ? (string) $row['description'] : null,
            $row['thumbnail_version'] !== null ? (int) $row['thumbnail_version'] : null,
            $row['preview_version'] !== null ? (int) $row['preview_version'] : null,
        ), $rows);
    }
}
