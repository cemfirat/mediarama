<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Infrastructure\Persistence\CollectionAccessSql;
use Mediarama\Media\Infrastructure\Persistence\AuthenticatedMediaAccessSql;
use Mediarama\Organization\Application\OrganizationMetadataRecord;
use Mediarama\Organization\Application\OrganizationMetadataSnapshotQuery;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOrganizationMetadataSnapshotQuery implements OrganizationMetadataSnapshotQuery
{
    private const MAX_SCOPE_MEDIA = 50000;

    public function __construct(private Connection $connection)
    {
    }

    public function snapshot(
        Uuid $requesterId,
        array $mediaIds,
    ): array {
        $mediaIds = $this->normalizeMediaIds($mediaIds);
        $idStrings = array_map(
            static fn (Uuid $id): string => $id->toRfc4122(),
            $mediaIds,
        );

        $rows = $this->connection->fetchAllAssociative(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT
    m.id,
    m.media_type,
    m.captured_at,
    m.creator,
    m.camera_make,
    m.camera_model,
    m.lens,
    m.location_name,
    m.width,
    m.height,
    (
        SELECT AVG(r.value)::double precision
        FROM ratings r
        WHERE r.media_id = m.id
    ) AS rating_average,
    EXISTS (
        SELECT 1
        FROM collection_media cm
        WHERE cm.media_id = m.id
    ) AS has_collection_membership
FROM media_assets m
WHERE m.id IN (:media_ids)
  AND '.AuthenticatedMediaAccessSql::predicate('m').'
ORDER BY m.id ASC',
            [
                'user' => $requesterId->toRfc4122(),
                'media_ids' => $idStrings,
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );

        if (count($rows) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Organization analysis scope contains unavailable media.',
            );
        }

        $tagRows = $this->connection->fetchAllAssociative(
            'SELECT mt.media_id, t.name
             FROM media_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.media_id IN (:media_ids)
             ORDER BY mt.media_id ASC, t.name ASC, t.id ASC',
            ['media_ids' => $idStrings],
            ['media_ids' => ArrayParameterType::STRING],
        );

        /** @var array<string,list<string>> $tags */
        $tags = [];
        foreach ($tagRows as $tagRow) {
            $mediaId = (string) $tagRow['media_id'];
            $name = trim((string) $tagRow['name']);
            if ($name === '') {
                continue;
            }

            $tags[$mediaId] ??= [];
            if (!in_array($name, $tags[$mediaId], true)) {
                $tags[$mediaId][] = $name;
            }
        }

        return array_map(
            fn (array $row): OrganizationMetadataRecord => new OrganizationMetadataRecord(
                Uuid::fromString((string) $row['id']),
                (string) $row['media_type'],
                $row['captured_at'] !== null
                    ? new DateTimeImmutable((string) $row['captured_at'])
                    : null,
                $row['creator'] !== null ? (string) $row['creator'] : null,
                $row['camera_make'] !== null ? (string) $row['camera_make'] : null,
                $row['camera_model'] !== null ? (string) $row['camera_model'] : null,
                $row['lens'] !== null ? (string) $row['lens'] : null,
                $row['location_name'] !== null ? (string) $row['location_name'] : null,
                $row['width'] !== null ? (int) $row['width'] : null,
                $row['height'] !== null ? (int) $row['height'] : null,
                $row['rating_average'] !== null
                    ? (float) $row['rating_average']
                    : null,
                $tags[(string) $row['id']] ?? [],
                $this->toBoolean($row['has_collection_membership']),
            ),
            $rows,
        );
    }

    /**
     * @param list<mixed> $mediaIds
     * @return list<Uuid>
     */
    private function normalizeMediaIds(array $mediaIds): array
    {
        if (
            $mediaIds === []
            || count($mediaIds) > self::MAX_SCOPE_MEDIA
        ) {
            throw new \InvalidArgumentException(
                'Organization analysis scope must contain 1-50000 MediaAssets.',
            );
        }

        $unique = [];
        foreach ($mediaIds as $mediaId) {
            if (!$mediaId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Organization analysis scope must contain UUID objects only.',
                );
            }

            $unique[$mediaId->toRfc4122()] = $mediaId;
        }

        if (count($unique) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Organization analysis scope must not contain duplicate MediaAssets.',
            );
        }

        return array_values($unique);
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower((string) $value),
            ['1', 't', 'true', 'yes', 'on'],
            true,
        );
    }
}
