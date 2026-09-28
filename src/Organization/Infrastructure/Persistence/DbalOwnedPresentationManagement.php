<?php

declare(strict_types=1);

namespace Mediarama\Organization\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Organization\Application\OwnedPresentationManagement;
use Mediarama\Publishing\Application\PublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

final readonly class DbalOwnedPresentationManagement implements OwnedPresentationManagement
{
    public function __construct(
        private Connection $connection,
        private MediaAssetRepository $media,
        private PublicPublicationTimelineStore $timeline,
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

        if ((bool) $this->connection->fetchOne(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM media_assets m
    WHERE m.id = :media
      AND m.deleted_at IS NULL
      AND m.processing_state = 'ready'
      AND m.moderation_state = 'published'
      AND EXISTS (
          SELECT 1
          FROM collection_media membership
          JOIN effective_public_collections visible
            ON visible.collection_id = membership.collection_id
          WHERE membership.media_id = m.id
      )
)
SQL,
            ['media' => $mediaId->toRfc4122()],
        )) {
            $this->timeline->touchMediaPublicContent(
                $mediaId,
                new DateTimeImmutable(),
            );
        }
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
                'SELECT id, visibility
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

            $changedAt = new DateTimeImmutable();
            $sets = ['updated_at = :updated'];
            $params = [
                'collection' => $collectionId->toRfc4122(),
                'updated' => $changedAt->format(DATE_ATOM),
            ];

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

            if ((string) $row['visibility'] === 'public') {
                $this->timeline->touchCollectionPublicContent(
                    $collectionId,
                    $changedAt,
                );
            }
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
                'SELECT mode, visibility
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
            $isPublic = (string) $collection['visibility'] === 'public';

            if ($mode === 'manual') {
                $eligible = (bool) $connection->fetchOne(
                    'SELECT EXISTS (
                        SELECT 1
                        FROM collection_media cm
                        JOIN media_assets m ON m.id = cm.media_id
                        WHERE cm.collection_id = :collection
                          AND cm.media_id = :media
                          AND m.deleted_at IS NULL
                          AND m.processing_state = \'ready\'
                          AND m.media_type = \'image\'
                          AND EXISTS (
                              SELECT 1
                              FROM media_derivatives derivative
                              WHERE derivative.media_id = m.id
                                AND derivative.kind = \'image\'
                                AND derivative.profile = \'thumbnail\'
                          )
                          AND (
                              :public = FALSE
                              OR m.moderation_state = \'published\'
                          )
                    )',
                    [
                        'collection' => $collectionId->toRfc4122(),
                        'media' => $mediaId->toRfc4122(),
                        'public' => $isPublic,
                    ],
                    ['public' => \Doctrine\DBAL\ParameterType::BOOLEAN],
                );
            } elseif ($isPublic) {
                $eligible = (bool) $connection->fetchOne(
                    <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM media_assets m
    WHERE m.id = :media
      AND m.owner_id = :owner
      AND m.deleted_at IS NULL
      AND m.processing_state = 'ready'
      AND m.moderation_state = 'published'
      AND m.media_type = 'image'
      AND EXISTS (
          SELECT 1
          FROM collection_media membership
          JOIN effective_public_collections public_collection
            ON public_collection.collection_id = membership.collection_id
          WHERE membership.media_id = m.id
      )
      AND EXISTS (
          SELECT 1
          FROM media_derivatives derivative
          WHERE derivative.media_id = m.id
            AND derivative.kind = 'image'
            AND derivative.profile = 'thumbnail'
      )
)
SQL,
                    [
                        'media' => $mediaId->toRfc4122(),
                        'owner' => $ownerId->toRfc4122(),
                    ],
                );
            } else {
                $eligible = (bool) $connection->fetchOne(
                    'SELECT EXISTS (
                        SELECT 1
                        FROM media_assets m
                        WHERE m.id = :media
                          AND m.owner_id = :owner
                          AND m.deleted_at IS NULL
                          AND m.processing_state = \'ready\'
                          AND m.media_type = \'image\'
                          AND EXISTS (
                              SELECT 1
                              FROM media_derivatives derivative
                              WHERE derivative.media_id = m.id
                                AND derivative.kind = \'image\'
                                AND derivative.profile = \'thumbnail\'
                          )
                    )',
                    [
                        'media' => $mediaId->toRfc4122(),
                        'owner' => $ownerId->toRfc4122(),
                    ],
                );
            }

            if (!$eligible) {
                throw new \DomainException(
                    'Collection cover MediaAsset is no longer eligible.',
                );
            }

            $changedAt = new DateTimeImmutable();
            $connection->executeStatement(
                'UPDATE collections
                 SET cover_media_id = :media,
                     updated_at = :updated
                 WHERE id = :collection',
                [
                    'media' => $mediaId->toRfc4122(),
                    'updated' => $changedAt->format(DATE_ATOM),
                    'collection' => $collectionId->toRfc4122(),
                ],
            );

            if ($isPublic) {
                $this->timeline->touchCollectionPublicContent(
                    $collectionId,
                    $changedAt,
                );
            }
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
