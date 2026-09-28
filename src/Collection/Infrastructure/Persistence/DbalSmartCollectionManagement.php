<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\SmartCollectionManagement;
use Mediarama\Collection\Application\SmartCollectionManagementResult;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSmartCollectionManagement implements SmartCollectionManagement
{
    public function __construct(private Connection $connection)
    {
    }

    public function owned(Uuid $ownerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT id, title, smart_rule, created_at, updated_at
FROM collections
WHERE owner_id = :owner
  AND mode = 'smart'
  AND visibility = 'private'
  AND deleted_at IS NULL
ORDER BY updated_at DESC, id DESC
LIMIT 100
SQL,
            ['owner' => $ownerId->toRfc4122()],
        );

        return array_map(
            fn (array $row): SmartCollectionManagementResult => $this->map($row),
            $rows,
        );
    }

    public function getOwned(
        Uuid $ownerId,
        Uuid $collectionId,
    ): ?SmartCollectionManagementResult {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT id, title, smart_rule, created_at, updated_at
FROM collections
WHERE id = :collection
  AND owner_id = :owner
  AND mode = 'smart'
  AND visibility = 'private'
  AND deleted_at IS NULL
SQL,
            [
                'collection' => $collectionId->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
            ],
        );

        return $row === false ? null : $this->map($row);
    }

    public function create(
        Uuid $ownerId,
        string $title,
        SmartCollectionRule $rule,
    ): Uuid {
        $title = $this->title($title);
        $id = Uuid::v7();
        $now = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO collections (
    id,
    owner_id,
    title,
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
    'private',
    0,
    :created_at,
    :updated_at,
    'smart',
    CAST(:smart_rule AS jsonb)
)
SQL,
            [
                'id' => $id->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
                'smart_rule' => $rule->toJson(),
            ],
        );

        return $id;
    }

    public function update(
        Uuid $ownerId,
        Uuid $collectionId,
        string $title,
        SmartCollectionRule $rule,
    ): void {
        $title = $this->title($title);

        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $collectionId,
            $title,
            $rule,
        ): void {
            $this->assertOwnedSmart(
                $connection,
                $ownerId,
                $collectionId,
            );

            $connection->executeStatement(
                <<<'SQL'
UPDATE collections
SET title = :title,
    smart_rule = CAST(:smart_rule AS jsonb),
    updated_at = CURRENT_TIMESTAMP
WHERE id = :collection
SQL,
                [
                    'title' => $title,
                    'smart_rule' => $rule->toJson(),
                    'collection' => $collectionId->toRfc4122(),
                ],
            );
        });
    }

    public function delete(
        Uuid $ownerId,
        Uuid $collectionId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $collectionId,
        ): void {
            $this->assertOwnedSmart(
                $connection,
                $ownerId,
                $collectionId,
            );

            $connection->executeStatement(
                <<<'SQL'
UPDATE collections
SET deleted_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :collection
SQL,
                ['collection' => $collectionId->toRfc4122()],
            );
        });
    }

    private function assertOwnedSmart(
        Connection $connection,
        Uuid $ownerId,
        Uuid $collectionId,
    ): void {
        $exists = (bool) $connection->fetchOne(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM collections
    WHERE id = :collection
      AND owner_id = :owner
      AND mode = 'smart'
      AND visibility = 'private'
      AND deleted_at IS NULL
    FOR UPDATE
)
SQL,
            [
                'collection' => $collectionId->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
            ],
        );

        if (!$exists) {
            throw new SmartCollectionUnavailableException(
                'Smart Collection is unavailable.',
            );
        }
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): SmartCollectionManagementResult
    {
        $rule = $row['smart_rule'];
        if (is_string($rule)) {
            $rule = json_decode($rule, true, flags: JSON_THROW_ON_ERROR);
        }

        if (!is_array($rule)) {
            throw new \RuntimeException('Persisted Smart Collection rule is invalid.');
        }

        return new SmartCollectionManagementResult(
            Uuid::fromString((string) $row['id']),
            (string) $row['title'],
            SmartCollectionRule::fromArray($rule),
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function title(string $title): string
    {
        $title = trim($title);
        $length = iconv_strlen($title, 'UTF-8');

        if ($title === '' || $length === false || $length > 200) {
            throw new \InvalidArgumentException(
                'Smart Collection title must contain 1-200 characters.',
            );
        }

        return $title;
    }
}
