<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Infrastructure\Persistence\CollectionAccessSql;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Persistence\AuthenticatedMediaAccessSql;
use Mediarama\Organization\Application\OrganizationAiPresentationAsset;
use Mediarama\Organization\Application\OrganizationAiPresentationAssetReader;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOrganizationAiPresentationAssetReader implements OrganizationAiPresentationAssetReader
{
    private const MAX_SCOPE_MEDIA = 50000;
    private const MAX_BYTES = 10485760;

    public function __construct(
        private Connection $connection,
        private MediaStorage $storage,
    ) {
    }

    public function available(
        Uuid $requesterId,
        array $mediaIds,
    ): array {
        $mediaIds = $this->normalizeMediaIds($mediaIds);
        $rows = $this->rows($requesterId, $mediaIds);

        $available = [];
        foreach ($rows as $row) {
            if (!$this->validPresentationRow($row)) {
                continue;
            }

            $storage = new StorageObjectId(
                (string) $row['storage_disk'],
                (string) $row['storage_key'],
            );

            if ($this->storage->exists($storage)) {
                $available[] = Uuid::fromString((string) $row['media_id']);
            }
        }

        return $available;
    }

    public function read(
        Uuid $requesterId,
        Uuid $mediaId,
    ): OrganizationAiPresentationAsset {
        $rows = $this->rows($requesterId, [$mediaId]);
        $row = $rows[0] ?? null;

        if ($row === null || !$this->validPresentationRow($row)) {
            throw new \DomainException(
                'Approved Organization AI presentation derivative is unavailable.',
            );
        }

        $storage = new StorageObjectId(
            (string) $row['storage_disk'],
            (string) $row['storage_key'],
        );

        if (!$this->storage->exists($storage)) {
            throw new \DomainException(
                'Approved Organization AI presentation derivative is unavailable.',
            );
        }

        $stream = $this->storage->read($storage);
        try {
            $bytes = stream_get_contents(
                $stream,
                self::MAX_BYTES + 1,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            throw new \DomainException(
                'Approved Organization AI presentation derivative cannot be read safely.',
            );
        }

        return new OrganizationAiPresentationAsset(
            $mediaId,
            (string) $row['mime_type'],
            (int) $row['width'],
            (int) $row['height'],
            $bytes,
        );
    }

    /**
     * @param list<Uuid> $mediaIds
     * @return list<array<string,mixed>>
     */
    private function rows(
        Uuid $requesterId,
        array $mediaIds,
    ): array {
        $ids = array_map(
            static fn (Uuid $id): string => $id->toRfc4122(),
            $mediaIds,
        );

        return $this->connection->fetchAllAssociative(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT
    m.id AS media_id,
    derivative.storage_disk,
    derivative.storage_key,
    derivative.mime_type,
    derivative.byte_size,
    derivative.width,
    derivative.height
FROM media_assets m
JOIN LATERAL (
    SELECT
        d.storage_disk,
        d.storage_key,
        d.mime_type,
        d.byte_size,
        d.width,
        d.height
    FROM media_derivatives d
    WHERE d.media_id = m.id
      AND (
          (
              m.media_type = \'image\'
              AND d.kind = \'image\'
              AND d.profile IN (\'preview\', \'thumbnail\')
          )
          OR (
              m.media_type = \'video\'
              AND d.kind = \'video\'
              AND d.profile = \'poster\'
          )
      )
    ORDER BY
        CASE
            WHEN m.media_type = \'image\' AND d.profile = \'preview\' THEN 1
            WHEN m.media_type = \'image\' AND d.profile = \'thumbnail\' THEN 2
            WHEN m.media_type = \'video\' AND d.profile = \'poster\' THEN 1
            ELSE 9
        END ASC,
        d.processing_version DESC
    LIMIT 1
) derivative ON TRUE
WHERE m.id IN (:media_ids)
  AND '.AuthenticatedMediaAccessSql::predicate('m').'
ORDER BY m.id ASC',
            [
                'user' => $requesterId->toRfc4122(),
                'media_ids' => $ids,
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );
    }

    /** @param array<string,mixed> $row */
    private function validPresentationRow(array $row): bool
    {
        $bytes = (int) $row['byte_size'];
        $width = $row['width'] !== null ? (int) $row['width'] : 0;
        $height = $row['height'] !== null ? (int) $row['height'] : 0;

        return $bytes > 0
            && $bytes <= self::MAX_BYTES
            && $width > 0
            && $height > 0
            && $width <= 4096
            && $height <= 4096
            && str_starts_with((string) $row['mime_type'], 'image/');
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
                'Organization AI presentation scope must contain 1-50000 MediaAssets.',
            );
        }

        $unique = [];
        foreach ($mediaIds as $mediaId) {
            if (!$mediaId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Organization AI presentation scope must contain UUID objects only.',
                );
            }

            $unique[$mediaId->toRfc4122()] = $mediaId;
        }

        if (count($unique) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Organization AI presentation scope must not contain duplicates.',
            );
        }

        return array_values($unique);
    }
}
