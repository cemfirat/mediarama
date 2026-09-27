<?php

declare(strict_types=1);

namespace Mediarama\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Platform\Application\PublicIndexSubjectRepository;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

final readonly class DbalPublicIndexSubjectRepository implements PublicIndexSubjectRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function collectionPolicy(Uuid $collectionId): ?SearchIndexPolicy
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
SELECT c.index_policy
FROM collections c
JOIN effective_public_collections public_collection
  ON public_collection.collection_id = c.id
WHERE c.id = :id
SQL,
            ['id' => $collectionId->toRfc4122()],
        );

        return $value === false ? null : SearchIndexPolicy::from((string) $value);
    }

    public function mediaPolicy(Uuid $mediaId): ?SearchIndexPolicy
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
SELECT m.index_policy
FROM media_assets m
WHERE m.id = :id
  AND m.deleted_at IS NULL
  AND m.processing_state = 'ready'
  AND m.moderation_state = 'published'
  AND EXISTS (
      SELECT 1
      FROM collection_media membership
      JOIN effective_public_collections public_collection
        ON public_collection.collection_id = membership.collection_id
      WHERE membership.media_id = m.id
  )
SQL,
            ['id' => $mediaId->toRfc4122()],
        );

        return $value === false ? null : SearchIndexPolicy::from((string) $value);
    }
}
