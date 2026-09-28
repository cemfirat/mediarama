<?php

declare(strict_types=1);

namespace Mediarama\Platform\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Platform\Application\SearchIndexPolicyRepository;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSearchIndexPolicyRepository implements SearchIndexPolicyRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function setCollectionPolicy(Uuid $collectionId, SearchIndexPolicy $policy): void
    {
        $this->update('collections', $collectionId, $policy);
    }

    public function setMediaPolicy(Uuid $mediaId, SearchIndexPolicy $policy): void
    {
        $this->update('media_assets', $mediaId, $policy);
    }

    private function update(string $table, Uuid $id, SearchIndexPolicy $policy): void
    {
        if (!in_array($table, ['collections', 'media_assets'], true)) {
            throw new \LogicException('Unsupported search-index policy target.');
        }

        $updated = $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET search_index_policy = :policy,
                     updated_at = :updated
                 WHERE id = :id
                   AND deleted_at IS NULL',
                $table,
            ),
            [
                'policy' => $policy->value,
                'updated' => (new DateTimeImmutable())->format(DATE_ATOM),
                'id' => $id->toRfc4122(),
            ],
        );

        if ($updated !== 1) {
            throw new \DomainException('Search-index policy target not found.');
        }
    }
}
