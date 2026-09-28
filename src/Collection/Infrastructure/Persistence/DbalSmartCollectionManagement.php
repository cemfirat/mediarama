<?php

declare(strict_types=1);

namespace Mediarama\Collection\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Collection\Application\SmartCollectionManagement;
use Mediarama\Collection\Application\SmartCollectionManagementResult;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Domain\Visibility;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSmartCollectionManagement implements SmartCollectionManagement
{
    public function __construct(private Connection $connection)
    {
    }

    public function owned(Uuid $ownerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->select().'
WHERE owner_id = :owner
  AND mode = \'smart\'
  AND visibility IN (\'private\', \'public\')
  AND deleted_at IS NULL
ORDER BY updated_at DESC, id DESC
LIMIT 100',
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
            $this->select().'
WHERE id = :collection
  AND owner_id = :owner
  AND mode = \'smart\'
  AND visibility IN (\'private\', \'public\')
  AND deleted_at IS NULL',
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
        ?string $description,
        SmartCollectionRule $rule,
    ): Uuid {
        $title = $this->title($title);
        $description = $this->description($description);
        $id = Uuid::v7();
        $now = (new DateTimeImmutable())->format(DATE_ATOM);

        $this->connection->executeStatement(
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
    'smart',
    CAST(:smart_rule AS jsonb)
)
SQL,
            [
                'id' => $id->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
                'title' => $title,
                'description' => $description,
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
        ?string $description,
        SmartCollectionRule $rule,
    ): void {
        $title = $this->title($title);
        $description = $this->description($description);

        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $collectionId,
            $title,
            $description,
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
    description = :description,
    smart_rule = CAST(:smart_rule AS jsonb),
    public_updated_at = CASE
        WHEN visibility = 'public' THEN CURRENT_TIMESTAMP
        ELSE public_updated_at
    END,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :collection
SQL,
                [
                    'title' => $title,
                    'description' => $description,
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
        $lockedId = $connection->fetchOne(
            <<<'SQL'
SELECT id
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

        if ($lockedId === false) {
            throw new SmartCollectionUnavailableException(
                'Smart Collection is unavailable.',
            );
        }
    }

    private function select(): string
    {
        return <<<'SQL'
SELECT
    id,
    title,
    description,
    smart_rule,
    visibility,
    search_index_policy,
    cover_media_id,
    public_published_at,
    created_at,
    updated_at
FROM collections
SQL;
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
            $row['description'] !== null ? (string) $row['description'] : null,
            SmartCollectionRule::fromArray($rule),
            Visibility::from((string) $row['visibility']),
            SearchIndexPolicy::from((string) $row['search_index_policy']),
            $row['cover_media_id'] !== null
                ? Uuid::fromString((string) $row['cover_media_id'])
                : null,
            $row['public_published_at'] !== null
                ? new DateTimeImmutable((string) $row['public_published_at'])
                : null,
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

    private function description(?string $description): ?string
    {
        $description = trim((string) $description);
        if ($description === '') {
            return null;
        }

        $length = iconv_strlen($description, 'UTF-8');
        if ($length === false || $length > 5000) {
            throw new \InvalidArgumentException(
                'Smart Collection description must not exceed 5000 characters.',
            );
        }

        return $description;
    }
}
