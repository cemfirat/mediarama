<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\ManualCollectionManagement;
use Mediarama\Media\Infrastructure\Persistence\AuthenticatedMediaAccessSql;
use Symfony\Component\Uid\Uuid;

final readonly class DbalManualCollectionManagement implements ManualCollectionManagement
{
    private const MAX_MEDIA = 50000;

    public function __construct(private Connection $connection)
    {
    }

    public function createPrivate(
        Uuid $ownerId,
        string $title,
        ?string $description,
        array $mediaIds,
    ): Uuid {
        $title = $this->title($title);
        $description = $this->description($description);
        $mediaIds = $this->mediaIds($mediaIds);

        return $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $title,
            $description,
            $mediaIds,
        ): Uuid {
            $this->assertVisible($connection, $ownerId, $mediaIds);

            $id = Uuid::v7();
            $now = (new DateTimeImmutable())->format(DATE_ATOM);

            $connection->executeStatement(
                <<<'SQL'
INSERT INTO collections (
    id,
    owner_id,
    title,
    description,
    visibility,
    position,
    created_at,
    updated_at,
    mode,
    smart_rule
) VALUES (
    :id,
    :owner,
    :title,
    :description,
    'private',
    0,
    :created_at,
    :updated_at,
    'manual',
    NULL
)
SQL,
                [
                    'id' => $id->toRfc4122(),
                    'owner' => $ownerId->toRfc4122(),
                    'title' => $title,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            foreach (array_chunk($mediaIds, 500) as $chunkIndex => $chunk) {
                $values = [];
                $parameters = [
                    'collection' => $id->toRfc4122(),
                    'owner' => $ownerId->toRfc4122(),
                    'created_at' => $now,
                ];
                $offset = $chunkIndex * 500;

                foreach ($chunk as $index => $mediaId) {
                    $name = 'media_'.$index;
                    $values[] = sprintf(
                        '(:collection, :%s, %d, :owner, :created_at)',
                        $name,
                        $offset + $index,
                    );
                    $parameters[$name] = $mediaId->toRfc4122();
                }

                $connection->executeStatement(
                    'INSERT INTO collection_media (
                        collection_id,
                        media_id,
                        position,
                        added_by,
                        created_at
                     ) VALUES '.implode(', ', $values),
                    $parameters,
                );
            }

            return $id;
        });
    }

    /** @param list<Uuid> $mediaIds */
    private function assertVisible(
        Connection $connection,
        Uuid $ownerId,
        array $mediaIds,
    ): void {
        $count = (int) $connection->fetchOne(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT COUNT(*)
FROM media_assets m
WHERE m.id IN (:media_ids)
  AND '.AuthenticatedMediaAccessSql::predicate('m'),
            [
                'user' => $ownerId->toRfc4122(),
                'media_ids' => array_map(
                    static fn (Uuid $id): string => $id->toRfc4122(),
                    $mediaIds,
                ),
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );

        if ($count !== count($mediaIds)) {
            throw new \DomainException(
                'Manual Collection proposal contains unavailable MediaAssets.',
            );
        }
    }

    /**
     * @param list<mixed> $mediaIds
     * @return list<Uuid>
     */
    private function mediaIds(array $mediaIds): array
    {
        if ($mediaIds === [] || count($mediaIds) > self::MAX_MEDIA) {
            throw new \InvalidArgumentException(
                'Manual Collection requires 1-50000 MediaAssets.',
            );
        }

        $unique = [];
        foreach ($mediaIds as $mediaId) {
            if (!$mediaId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Manual Collection media IDs are invalid.',
                );
            }
            $unique[$mediaId->toRfc4122()] = $mediaId;
        }

        if (count($unique) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Manual Collection media IDs must be unique.',
            );
        }

        return array_values($unique);
    }

    private function title(string $title): string
    {
        $title = trim($title);
        $length = iconv_strlen($title, 'UTF-8');

        if ($title === '' || $length === false || $length > 200) {
            throw new \InvalidArgumentException(
                'Manual Collection title must contain 1-200 characters.',
            );
        }

        return $title;
    }

    private function description(?string $description): ?string
    {
        $description = trim((string) $description);
        if ($description === '') {
            return null;
        }

        $length = iconv_strlen($description, 'UTF-8');
        if ($length === false || $length > 5000) {
            throw new \InvalidArgumentException(
                'Manual Collection description must not exceed 5000 characters.',
            );
        }

        return $description;
    }
}
