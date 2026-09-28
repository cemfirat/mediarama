<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\SmartCollectionPublication;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Publishing\Application\PublicPublicationTimelineStore;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSmartCollectionPublication implements SmartCollectionPublication
{
    public function __construct(
        private Connection $connection,
        private PublicPublicationTimelineStore $timeline,
    ) {
    }

    public function publish(
        Uuid $ownerId,
        Uuid $collectionId,
        SearchIndexPolicy $indexPolicy,
        ?Uuid $coverMediaId = null,
    ): void {
        if ($indexPolicy === SearchIndexPolicy::Inherit) {
            throw new \InvalidArgumentException(
                'Publishing a Smart Collection requires an explicit index or noindex decision.',
            );
        }

        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $collectionId,
            $indexPolicy,
            $coverMediaId,
        ): void {
            $row = $this->lockOwnedSmart(
                $connection,
                $ownerId,
                $collectionId,
            );
            $this->assertPublicCover(
                $connection,
                $ownerId,
                $coverMediaId,
            );

            $wasPublic = (string) $row['visibility'] === 'public';
            $now = new DateTimeImmutable();

            $connection->executeStatement(
                <<<'SQL'
UPDATE collections
SET visibility = 'public',
    password_protected = FALSE,
    password_hash = NULL,
    password_hint = NULL,
    password_reset_required = FALSE,
    search_index_policy = :index_policy,
    cover_media_id = :cover_media_id,
    updated_at = :updated
WHERE id = :collection
SQL,
                [
                    'index_policy' => $indexPolicy->value,
                    'cover_media_id' => $coverMediaId?->toRfc4122(),
                    'updated' => $now->format(DATE_ATOM),
                    'collection' => $collectionId->toRfc4122(),
                ],
            );

            $this->timeline->recordCollectionFirstPublication(
                $collectionId,
                $now,
            );

            if ($wasPublic) {
                $this->timeline->touchCollectionPublicContent(
                    $collectionId,
                    $now,
                );
            }
        });
    }

    public function unpublish(
        Uuid $ownerId,
        Uuid $collectionId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $collectionId,
        ): void {
            $this->lockOwnedSmart(
                $connection,
                $ownerId,
                $collectionId,
            );

            $connection->executeStatement(
                <<<'SQL'
UPDATE collections
SET visibility = 'private',
    updated_at = CURRENT_TIMESTAMP
WHERE id = :collection
SQL,
                ['collection' => $collectionId->toRfc4122()],
            );
        });
    }

    private function assertPublicCover(
        Connection $connection,
        Uuid $ownerId,
        ?Uuid $coverMediaId,
    ): void {
        if ($coverMediaId === null) {
            return;
        }

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
                'media' => $coverMediaId->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
            ],
        );

        if (!$eligible) {
            throw new \InvalidArgumentException(
                'The Smart Collection cover must be an already-public owned image with a thumbnail derivative.',
            );
        }
    }

    /** @return array{visibility:string} */
    private function lockOwnedSmart(
        Connection $connection,
        Uuid $ownerId,
        Uuid $collectionId,
    ): array {
        $row = $connection->fetchAssociative(
            <<<'SQL'
SELECT visibility
FROM collections
WHERE id = :collection
  AND owner_id = :owner
  AND mode = 'smart'
  AND visibility IN ('private', 'public')
  AND deleted_at IS NULL
FOR UPDATE
SQL,
            [
                'collection' => $collectionId->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
            ],
        );

        if ($row === false) {
            throw new SmartCollectionUnavailableException(
                'Smart Collection is unavailable.',
            );
        }

        return ['visibility' => (string) $row['visibility']];
    }
}
