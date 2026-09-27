<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Collection\Application\PublicCollectionResult;
use Mediarama\Collection\Application\PublicGalleryQuery;
use Mediarama\Collection\Application\PublicMediaResult;
use Symfony\Component\Uid\Uuid;

final readonly class DbalPublicGalleryQuery implements PublicGalleryQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function rootCollections(): array
    {
        return $this->collections(null);
    }

    public function collection(Uuid $id): ?PublicCollectionResult
    {
        $row = $this->connection->fetchAssociative(
            $this->collectionSelect().' WHERE c.id = :id',
            ['id' => $id->toRfc4122()],
        );

        return $row === false ? null : $this->mapCollection($row);
    }

    public function childCollections(Uuid $parentId): array
    {
        return $this->collections($parentId);
    }

    public function media(Uuid $collectionId, int $limit = 120, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 240 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid public gallery pagination.');
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    m.id,
    m.title,
    m.description,
    m.mime_type,
    m.media_type,
    m.width,
    m.height,
    cm.position,
    thumbnail.processing_version AS thumbnail_version,
    preview.processing_version AS preview_version
FROM effective_public_collections epc
JOIN collections c ON c.id = epc.collection_id
JOIN collection_media cm ON cm.collection_id = c.id
JOIN media_assets m ON m.id = cm.media_id
LEFT JOIN LATERAL (
    SELECT d.processing_version
    FROM media_derivatives d
    WHERE d.media_id = m.id
      AND d.kind = 'image'
      AND d.profile = 'thumbnail'
    ORDER BY d.processing_version DESC
    LIMIT 1
) thumbnail ON TRUE
LEFT JOIN LATERAL (
    SELECT d.processing_version
    FROM media_derivatives d
    WHERE d.media_id = m.id
      AND d.kind = 'image'
      AND d.profile = 'preview'
    ORDER BY d.processing_version DESC
    LIMIT 1
) preview ON TRUE
WHERE c.id = :collection
  AND m.deleted_at IS NULL
  AND m.processing_state = 'ready'
  AND m.moderation_state = 'published'
ORDER BY cm.position ASC, m.created_at ASC
LIMIT :limit OFFSET :offset
SQL,
            [
                'collection' => $collectionId->toRfc4122(),
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );

        return array_map(static fn (array $row): PublicMediaResult => new PublicMediaResult(
            Uuid::fromString((string) $row['id']),
            $row['title'] !== null ? (string) $row['title'] : null,
            $row['description'] !== null ? (string) $row['description'] : null,
            (string) $row['mime_type'],
            (string) $row['media_type'],
            $row['width'] !== null ? (int) $row['width'] : null,
            $row['height'] !== null ? (int) $row['height'] : null,
            (int) $row['position'],
            $row['thumbnail_version'] !== null ? (int) $row['thumbnail_version'] : null,
            $row['preview_version'] !== null ? (int) $row['preview_version'] : null,
        ), $rows);
    }

    public function canViewMedia(Uuid $mediaId): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM media_assets m
    JOIN collection_media cm ON cm.media_id = m.id
    JOIN effective_public_collections epc ON epc.collection_id = cm.collection_id
    WHERE m.id = :media
      AND m.deleted_at IS NULL
      AND m.processing_state = 'ready'
      AND m.moderation_state = 'published'
)
SQL,
            ['media' => $mediaId->toRfc4122()],
        );
    }

    private function collections(?Uuid $parentId): array
    {
        $where = $parentId === null
            ? 'c.parent_id IS NULL'
            : 'c.parent_id = :parent';
        $params = $parentId === null ? [] : ['parent' => $parentId->toRfc4122()];

        $rows = $this->connection->fetchAllAssociative(
            $this->collectionSelect().' WHERE '.$where.' ORDER BY c.position ASC, c.title ASC',
            $params,
        );

        return array_map($this->mapCollection(...), $rows);
    }

    private function collectionSelect(): string
    {
        return <<<'SQL'
SELECT
    c.id,
    c.title,
    c.description,
    (
        SELECT COUNT(*)
        FROM collection_media cm
        JOIN media_assets m ON m.id = cm.media_id
        WHERE cm.collection_id = c.id
          AND m.deleted_at IS NULL
          AND m.processing_state = 'ready'
          AND m.moderation_state = 'published'
    ) AS media_count,
    (
        SELECT COUNT(*)
        FROM collections child
        JOIN effective_public_collections visible_child ON visible_child.collection_id = child.id
        WHERE child.parent_id = c.id
    ) AS child_count,
    cover.media_id AS cover_media_id,
    cover.processing_version AS cover_thumbnail_version
FROM effective_public_collections visible
JOIN collections c ON c.id = visible.collection_id
LEFT JOIN LATERAL (
    SELECT m.id AS media_id, d.processing_version
    FROM collection_media cm
    JOIN media_assets m ON m.id = cm.media_id
    JOIN LATERAL (
        SELECT derivative.processing_version
        FROM media_derivatives derivative
        WHERE derivative.media_id = m.id
          AND derivative.kind = 'image'
          AND derivative.profile = 'thumbnail'
        ORDER BY derivative.processing_version DESC
        LIMIT 1
    ) d ON TRUE
    WHERE cm.collection_id = c.id
      AND m.deleted_at IS NULL
      AND m.processing_state = 'ready'
      AND m.moderation_state = 'published'
      AND m.media_type = 'image'
    ORDER BY (m.id = c.cover_media_id) DESC, cm.position ASC, cm.created_at ASC
    LIMIT 1
) cover ON TRUE

SQL;
    }

    private function mapCollection(array $row): PublicCollectionResult
    {
        return new PublicCollectionResult(
            Uuid::fromString((string) $row['id']),
            (string) $row['title'],
            $row['description'] !== null ? (string) $row['description'] : null,
            (int) $row['media_count'],
            (int) $row['child_count'],
            $row['cover_media_id'] !== null ? Uuid::fromString((string) $row['cover_media_id']) : null,
            $row['cover_thumbnail_version'] !== null ? (int) $row['cover_thumbnail_version'] : null,
        );
    }
}
