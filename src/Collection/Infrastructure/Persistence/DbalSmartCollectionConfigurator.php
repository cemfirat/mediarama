<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\SmartCollectionConfigurator;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSmartCollectionConfigurator implements SmartCollectionConfigurator
{
    public function __construct(private Connection $connection)
    {
    }

    public function configureSmart(
        Uuid $actorId,
        Uuid $collectionId,
        SmartCollectionRule $rule,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $actorId,
            $collectionId,
            $rule,
        ): void {
            $this->assertOwnedCollection(
                $connection,
                $actorId,
                $collectionId,
            );

            $membershipCount = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM collection_media WHERE collection_id = :collection',
                ['collection' => $collectionId->toRfc4122()],
            );

            if ($membershipCount > 0) {
                throw new SmartCollectionUnavailableException(
                    'A Collection with persisted manual membership cannot be converted to Smart mode implicitly.',
                );
            }

            $connection->executeStatement(
                <<<'SQL'
UPDATE collections
SET mode = 'smart',
    smart_rule = CAST(:smart_rule AS jsonb),
    updated_at = CURRENT_TIMESTAMP
WHERE id = :collection
SQL,
                [
                    'smart_rule' => $rule->toJson(),
                    'collection' => $collectionId->toRfc4122(),
                ],
            );
        });
    }

    public function configureManual(Uuid $actorId, Uuid $collectionId): void
    {
        $this->connection->transactional(function (Connection $connection) use (
            $actorId,
            $collectionId,
        ): void {
            $this->assertOwnedCollection(
                $connection,
                $actorId,
                $collectionId,
            );

            $connection->executeStatement(
                <<<'SQL'
UPDATE collections
SET mode = 'manual',
    smart_rule = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :collection
SQL,
                ['collection' => $collectionId->toRfc4122()],
            );
        });
    }

    private function assertOwnedCollection(
        Connection $connection,
        Uuid $actorId,
        Uuid $collectionId,
    ): void {
        $row = $connection->fetchAssociative(
            <<<'SQL'
SELECT owner_id
FROM collections
WHERE id = :collection
  AND deleted_at IS NULL
FOR UPDATE
SQL,
            ['collection' => $collectionId->toRfc4122()],
        );

        if (
            $row === false
            || $row['owner_id'] === null
            || (string) $row['owner_id'] !== $actorId->toRfc4122()
        ) {
            throw new SmartCollectionUnavailableException(
                'Smart Collection is unavailable.',
            );
        }
    }
}
