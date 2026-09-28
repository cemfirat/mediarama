<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Collection\Application\SmartCollectionMediaResult;
use Mediarama\Collection\Application\SmartCollectionResolver;
use Mediarama\Collection\Application\SmartCollectionRuleCompiler;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Media\Infrastructure\Persistence\AuthenticatedMediaAccessSql;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSmartCollectionResolver implements SmartCollectionResolver
{
    public function __construct(
        private Connection $connection,
        private SmartCollectionRuleCompiler $compiler,
    ) {
    }

    public function count(Uuid $actorId, Uuid $collectionId): int
    {
        [$ownerId, $rule] = $this->configuration($actorId, $collectionId);
        $predicate = $this->compiler->compile($rule);

        return (int) $this->connection->fetchOne(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT COUNT(*)
FROM media_assets m
WHERE m.owner_id = :collection_owner
  AND '.AuthenticatedMediaAccessSql::predicate('m').'
  AND '.$predicate->sql,
            [
                'user' => $actorId->toRfc4122(),
                'collection_owner' => $ownerId->toRfc4122(),
                ...$predicate->parameters,
            ],
        );
    }

    public function resolve(
        Uuid $actorId,
        Uuid $collectionId,
        int $limit = 50,
        int $offset = 0,
    ): array {
        if ($limit < 1 || $limit > 200 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid Smart Collection pagination.');
        }

        [$ownerId, $rule] = $this->configuration($actorId, $collectionId);
        $predicate = $this->compiler->compile($rule);

        $parameters = [
            'user' => $actorId->toRfc4122(),
            'collection_owner' => $ownerId->toRfc4122(),
            ...$predicate->parameters,
            'limit' => $limit,
            'offset' => $offset,
        ];

        $types = [
            'limit' => ParameterType::INTEGER,
            'offset' => ParameterType::INTEGER,
        ];

        $rows = $this->connection->fetchAllAssociative(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT
    m.id,
    m.media_type,
    m.title,
    m.captured_at,
    m.creator,
    m.camera_make,
    m.camera_model,
    m.lens,
    m.location_name
FROM media_assets m
WHERE m.owner_id = :collection_owner
  AND '.AuthenticatedMediaAccessSql::predicate('m').'
  AND '.$predicate->sql.'
ORDER BY
    m.captured_at DESC NULLS LAST,
    m.created_at DESC,
    m.id DESC
LIMIT :limit OFFSET :offset',
            $parameters,
            $types,
        );

        return array_map(
            static fn (array $row): SmartCollectionMediaResult => new SmartCollectionMediaResult(
                Uuid::fromString((string) $row['id']),
                (string) $row['media_type'],
                $row['title'] !== null ? (string) $row['title'] : null,
                $row['captured_at'] !== null
                    ? new DateTimeImmutable((string) $row['captured_at'])
                    : null,
                $row['creator'] !== null ? (string) $row['creator'] : null,
                $row['camera_make'] !== null ? (string) $row['camera_make'] : null,
                $row['camera_model'] !== null ? (string) $row['camera_model'] : null,
                $row['lens'] !== null ? (string) $row['lens'] : null,
                $row['location_name'] !== null ? (string) $row['location_name'] : null,
            ),
            $rows,
        );
    }

    /** @return array{0:Uuid,1:SmartCollectionRule} */
    private function configuration(
        Uuid $actorId,
        Uuid $collectionId,
    ): array {
        $row = $this->connection->fetchAssociative(
            CollectionAccessSql::authenticatedVisibleCollectionsCte().'
SELECT c.owner_id, c.smart_rule
FROM collections c
JOIN actor_visible_collections visible
  ON visible.collection_id = c.id
WHERE c.id = :collection
  AND c.mode = \'smart\'
  AND c.deleted_at IS NULL',
            [
                'user' => $actorId->toRfc4122(),
                'collection' => $collectionId->toRfc4122(),
            ],
        );

        if ($row === false || $row['owner_id'] === null || $row['smart_rule'] === null) {
            throw new SmartCollectionUnavailableException(
                'Smart Collection is unavailable.',
            );
        }

        $rawRule = $row['smart_rule'];
        if (is_string($rawRule)) {
            $rawRule = json_decode($rawRule, true, flags: JSON_THROW_ON_ERROR);
        }

        if (!is_array($rawRule)) {
            throw new \RuntimeException('Persisted Smart Collection rule is invalid.');
        }

        return [
            Uuid::fromString((string) $row['owner_id']),
            SmartCollectionRule::fromArray($rawRule),
        ];
    }
}
