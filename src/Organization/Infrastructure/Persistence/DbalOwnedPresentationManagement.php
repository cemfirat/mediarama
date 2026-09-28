<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Organization\Application\OwnedPresentationManagement;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOwnedPresentationManagement implements OwnedPresentationManagement
{
    public function __construct(
        private Connection $connection,
        private MediaAssetRepository $media,
    ) {
    }

    public function updateMedia(
        Uuid $ownerId,
        Uuid $mediaId,
        ?string $title,
        ?string $description,
    ): void {
        if ($title === null && $description === null) {
            throw new \InvalidArgumentException(
                'Media presentation proposal contains no change.',
            );
        }

        $asset = $this->media->get($mediaId);

        if ($asset->ownerId === null || !$asset->ownerId->equals($ownerId)) {
            throw new \DomainException(
                'Media presentation proposal may modify only requester-owned MediaAssets.',
            );
        }

        if ($title !== null) {
            $asset->editMetadata('title', $this->title($title));
        }

        if ($description !== null) {
            $asset->editMetadata(
                'description',
                $this->description($description),
            );
        }

        $this->media->save($asset);
    }

    public function updateCollection(
        Uuid $ownerId,
        Uuid $collectionId,
        ?string $title,
        ?string $description,
    ): void {
        if ($title === null && $description === null) {
            throw new \InvalidArgumentException(
                'Collection presentation proposal contains no change.',
            );
        }

        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $collectionId,
            $title,
            $description,
        ): void {
            $row = $connection->fetchAssociative(
                'SELECT id
                 FROM collections
                 WHERE id = :collection
                   AND owner_id = :owner
                   AND deleted_at IS NULL
                 FOR UPDATE',
                [
                    'collection' => $collectionId->toRfc4122(),
                    'owner' => $ownerId->toRfc4122(),
                ],
            );

            if ($row === false) {
                throw new \DomainException(
                    'Collection presentation proposal target is unavailable.',
                );
            }

            $sets = ['updated_at = CURRENT_TIMESTAMP'];
            $params = ['collection' => $collectionId->toRfc4122()];

            if ($title !== null) {
                $sets[] = 'title = :title';
                $params['title'] = $this->title($title);
            }

            if ($description !== null) {
                $sets[] = 'description = :description';
                $params['description'] = $this->description($description);
            }

            $connection->executeStatement(
                'UPDATE collections
                 SET '.implode(', ', $sets).'
                 WHERE id = :collection',
                $params,
            );
        });
    }

    public function setCollectionCover(
        Uuid $ownerId,
        Uuid $collectionId,
        Uuid $mediaId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $collectionId,
            $mediaId,
        ): void {
            $collection = $connection->fetchAssociative(
                'SELECT mode
                 FROM collections
                 WHERE id = :collection
                   AND owner_id = :owner
                   AND deleted_at IS NULL
                 FOR UPDATE',
                [
                    'collection' => $collectionId->toRfc4122(),
                    'owner' => $ownerId->toRfc4122(),
                ],
            );

            if ($collection === false) {
                throw new \DomainException(
                    'Collection cover proposal target is unavailable.',
                );
            }

            $mode = (string) $collection['mode'];
            $eligible = $mode === 'manual'
                ? (bool) $connection->fetchOne(
                    'SELECT EXISTS (
                        SELECT 1
                        FROM collection_media cm
                        JOIN media_assets m ON m.id = cm.media_id
                        WHERE cm.collection_id = :collection
                          AND cm.media_id = :media
                          AND m.deleted_at IS NULL
                          AND m.processing_state = \'ready\'
                    )',
                    [
                        'collection' => $collectionId->toRfc4122(),
                        'media' => $mediaId->toRfc4122(),
                    ],
                )
                : (bool) $connection->fetchOne(
                    'SELECT EXISTS (
                        SELECT 1
                        FROM media_assets m
                        WHERE m.id = :media
                          AND m.owner_id = :owner
                          AND m.deleted_at IS NULL
                          AND m.processing_state = \'ready\'
                    )',
                    [
                        'media' => $mediaId->toRfc4122(),
                        'owner' => $ownerId->toRfc4122(),
                    ],
                );

            if (!$eligible) {
                throw new \DomainException(
                    'Collection cover MediaAsset is no longer eligible.',
                );
            }

            $connection->executeStatement(
                'UPDATE collections
                 SET cover_media_id = :media,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :collection',
                [
                    'media' => $mediaId->toRfc4122(),
                    'collection' => $collectionId->toRfc4122(),
                ],
            );
        });
    }

    private function title(string $value): string
    {
        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');
        if ($value === '' || $length === false || $length > 200) {
            throw new \InvalidArgumentException(
                'Presentation title must contain 1-200 characters.',
            );
        }

        return $value;
    }

    private function description(string $value): string
    {
        $value = trim($value);
        $length = iconv_strlen($value, 'UTF-8');
        if ($value === '' || $length === false || $length > 5000) {
            throw new \InvalidArgumentException(
                'Presentation description must contain 1-5000 characters.',
            );
        }

        return $value;
    }
}
