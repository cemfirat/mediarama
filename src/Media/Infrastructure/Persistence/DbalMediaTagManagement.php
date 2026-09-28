<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaTagManagement;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

final readonly class DbalMediaTagManagement implements MediaTagManagement
{
    private const MAX_MEDIA = 50000;

    public function __construct(private Connection $connection)
    {
    }

    public function tagOwnedMedia(
        Uuid $ownerId,
        string $name,
        array $mediaIds,
    ): Uuid {
        $name = $this->name($name);
        $mediaIds = $this->mediaIds($mediaIds);
        $slug = $this->slug($name);

        return $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $name,
            $mediaIds,
            $slug,
        ): Uuid {
            $count = (int) $connection->fetchOne(
                'SELECT COUNT(*)
                 FROM media_assets
                 WHERE id IN (:media_ids)
                   AND owner_id = :owner
                   AND deleted_at IS NULL
                   AND processing_state = \'ready\'',
                [
                    'media_ids' => array_map(
                        static fn (Uuid $id): string => $id->toRfc4122(),
                        $mediaIds,
                    ),
                    'owner' => $ownerId->toRfc4122(),
                ],
                ['media_ids' => ArrayParameterType::STRING],
            );

            if ($count !== count($mediaIds)) {
                throw new \DomainException(
                    'Tag proposal may modify only MediaAssets owned by the requester.',
                );
            }

            $existing = $connection->fetchOne(
                'SELECT id FROM tags WHERE slug = :slug',
                ['slug' => $slug],
            );

            if ($existing === false) {
                $tagId = Uuid::v7();
                $now = (new DateTimeImmutable())->format(DATE_ATOM);
                $connection->insert('tags', [
                    'id' => $tagId->toRfc4122(),
                    'slug' => $slug,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $tagId = Uuid::fromString((string) $existing);
            }

            foreach ($mediaIds as $mediaId) {
                $connection->executeStatement(
                    'INSERT INTO media_tags (media_id, tag_id, source)
                     VALUES (:media, :tag, \'manual\')
                     ON CONFLICT (media_id, tag_id) DO NOTHING',
                    [
                        'media' => $mediaId->toRfc4122(),
                        'tag' => $tagId->toRfc4122(),
                    ],
                );
            }

            return $tagId;
        });
    }

    /**
     * @param list<mixed> $mediaIds
     * @return list<Uuid>
     */
    private function mediaIds(array $mediaIds): array
    {
        if ($mediaIds === [] || count($mediaIds) > self::MAX_MEDIA) {
            throw new \InvalidArgumentException(
                'Tag proposal requires 1-50000 MediaAssets.',
            );
        }

        $unique = [];
        foreach ($mediaIds as $mediaId) {
            if (!$mediaId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Tag proposal media IDs are invalid.',
                );
            }
            $unique[$mediaId->toRfc4122()] = $mediaId;
        }

        if (count($unique) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Tag proposal media IDs must be unique.',
            );
        }

        return array_values($unique);
    }

    private function name(string $name): string
    {
        $name = trim($name);
        $length = iconv_strlen($name, 'UTF-8');

        if ($name === '' || $length === false || $length > 160) {
            throw new \InvalidArgumentException(
                'Tag name must contain 1-160 characters.',
            );
        }

        return $name;
    }

    private function slug(string $name): string
    {
        $value = (new AsciiSlugger())
            ->slug($name)
            ->lower()
            ->toString();
        $value = trim(substr($value, 0, 160), '-');

        if ($value === '') {
            throw new \InvalidArgumentException(
                'Tag name cannot produce a stable slug.',
            );
        }

        return $value;
    }
}
