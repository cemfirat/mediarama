<?php

declare(strict_types=1);

namespace Mediarama\Seo\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Seo\Application\PublicSitemapQuery;
use Mediarama\Seo\Domain\PublicSitemapCollection;
use Mediarama\Seo\Domain\PublicSitemapImage;
use Mediarama\Seo\Domain\PublicSitemapMedia;
use Symfony\Component\Uid\Uuid;

final readonly class DbalPublicSitemapQuery implements PublicSitemapQuery
{
    public function __construct(
        private Connection $connection,
        private PlatformSettingsRepository $settings,
    ) {
    }

    public function indexableCollectionCount(): int
    {
        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*)
FROM effective_public_collections visible
JOIN collections c ON c.id = visible.collection_id
WHERE
    c.search_index_policy = 'index'
    OR (
        c.search_index_policy = 'inherit'
        AND :site_index_default = TRUE
    )
SQL,
            ['site_index_default' => $settings->searchIndexDefault === SearchIndexPolicy::Index],
            ['site_index_default' => ParameterType::BOOLEAN],
        );
    }

    public function indexableCollections(int $limit, int $offset): array
    {
        $this->assertPagination($limit, $offset);

        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            return [];
        }

        $siteIndexDefault = $settings->searchIndexDefault === SearchIndexPolicy::Index;

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
WITH selected_collections AS (
    SELECT
        c.id,
        ROW_NUMBER() OVER (
            ORDER BY c.position ASC, c.title ASC, c.id ASC
        ) AS sitemap_position
    FROM effective_public_collections visible
    JOIN collections c ON c.id = visible.collection_id
    WHERE
        c.search_index_policy = 'index'
        OR (
            c.search_index_policy = 'inherit'
            AND :site_index_default = TRUE
        )
    ORDER BY c.position ASC, c.title ASC, c.id ASC
    LIMIT :limit OFFSET :offset
)
SELECT
    selected.id AS collection_id,
    selected.sitemap_position,
    image.media_id,
    image.processing_version,
    image.profile,
    image.media_position,
    image.media_created_at
FROM selected_collections selected
LEFT JOIN LATERAL (
    SELECT
        page_media.id AS media_id,
        derivative.processing_version,
        derivative.profile,
        page_media.position AS media_position,
        page_media.created_at AS media_created_at
    FROM (
        SELECT
            m.id,
            m.media_type,
            m.search_index_policy,
            cm.position,
            m.created_at
        FROM collection_media cm
        JOIN media_assets m ON m.id = cm.media_id
        WHERE cm.collection_id = selected.id
          AND m.deleted_at IS NULL
          AND m.processing_state = 'ready'
          AND m.moderation_state = 'published'
        ORDER BY cm.position ASC, m.created_at ASC, m.id ASC
        LIMIT 120
    ) page_media
    JOIN LATERAL (
        SELECT
            d.processing_version,
            d.profile
        FROM media_derivatives d
        WHERE d.media_id = page_media.id
          AND d.kind = 'image'
          AND d.profile IN ('preview', 'thumbnail')
        ORDER BY
            CASE d.profile
                WHEN 'preview' THEN 1
                WHEN 'thumbnail' THEN 2
                ELSE 3
            END ASC,
            d.processing_version DESC
        LIMIT 1
    ) derivative ON TRUE
    WHERE page_media.media_type = 'image'
      AND (
          page_media.search_index_policy = 'index'
          OR (
              page_media.search_index_policy = 'inherit'
              AND :site_index_default = TRUE
          )
      )
    ORDER BY page_media.position ASC, page_media.created_at ASC, page_media.id ASC
) image ON TRUE
ORDER BY
    selected.sitemap_position ASC,
    image.media_position ASC NULLS LAST,
    image.media_created_at ASC NULLS LAST,
    image.media_id ASC NULLS LAST
SQL,
            [
                'site_index_default' => $siteIndexDefault,
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'site_index_default' => ParameterType::BOOLEAN,
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );

        /** @var array<string,array{id:Uuid,images:list<PublicSitemapImage>}> $collections */
        $collections = [];

        foreach ($rows as $row) {
            $collectionId = (string) $row['collection_id'];

            if (!isset($collections[$collectionId])) {
                $collections[$collectionId] = [
                    'id' => Uuid::fromString($collectionId),
                    'images' => [],
                ];
            }

            if ($row['media_id'] === null) {
                continue;
            }

            $collections[$collectionId]['images'][] = new PublicSitemapImage(
                Uuid::fromString((string) $row['media_id']),
                (int) $row['processing_version'],
                (string) $row['profile'],
            );
        }

        return array_map(
            static fn (array $collection): PublicSitemapCollection => new PublicSitemapCollection(
                $collection['id'],
                $collection['images'],
            ),
            array_values($collections),
        );
    }

    public function indexableMediaCount(): int
    {
        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            <<<'SQL'
SELECT COUNT(*)
FROM media_assets m
WHERE m.deleted_at IS NULL
  AND m.processing_state = 'ready'
  AND m.moderation_state = 'published'
  AND (
      m.search_index_policy = 'index'
      OR (
          m.search_index_policy = 'inherit'
          AND :site_index_default = TRUE
      )
  )
  AND EXISTS (
      SELECT 1
      FROM collection_media cm
      JOIN effective_public_collections visible
        ON visible.collection_id = cm.collection_id
      WHERE cm.media_id = m.id
  )
SQL,
            ['site_index_default' => $settings->searchIndexDefault === SearchIndexPolicy::Index],
            ['site_index_default' => ParameterType::BOOLEAN],
        );
    }

    public function indexableMedia(int $limit, int $offset): array
    {
        $this->assertPagination($limit, $offset);

        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            return [];
        }

        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT m.id
FROM media_assets m
WHERE m.deleted_at IS NULL
  AND m.processing_state = 'ready'
  AND m.moderation_state = 'published'
  AND (
      m.search_index_policy = 'index'
      OR (
          m.search_index_policy = 'inherit'
          AND :site_index_default = TRUE
      )
  )
  AND EXISTS (
      SELECT 1
      FROM collection_media cm
      JOIN effective_public_collections visible
        ON visible.collection_id = cm.collection_id
      WHERE cm.media_id = m.id
  )
ORDER BY m.created_at ASC, m.id ASC
LIMIT :limit OFFSET :offset
SQL,
            [
                'site_index_default' => $settings->searchIndexDefault === SearchIndexPolicy::Index,
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'site_index_default' => ParameterType::BOOLEAN,
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );

        return array_map(
            static fn (mixed $id): PublicSitemapMedia => new PublicSitemapMedia(
                Uuid::fromString((string) $id),
            ),
            $rows,
        );
    }

    private function assertPagination(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 1000 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid sitemap pagination.');
        }
    }
}
