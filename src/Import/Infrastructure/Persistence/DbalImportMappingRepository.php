<?php

declare(strict_types=1);

namespace Mediarama\Import\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Import\Application\ImportMappingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DbalImportMappingRepository implements ImportMappingRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findTargetId(string $sourceKey, string $entityType, string $sourceId): ?Uuid
    {
        $value = $this->connection->fetchOne(
            'SELECT target_id FROM import_mappings WHERE source_key = :source_key AND entity_type = :entity_type AND source_id = :source_id',
            ['source_key' => $sourceKey, 'entity_type' => $entityType, 'source_id' => $sourceId],
        );

        return $value === false ? null : Uuid::fromString((string) $value);
    }

    public function remember(string $sourceKey, string $entityType, string $sourceId, Uuid $targetId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO import_mappings (source_key, entity_type, source_id, target_id, imported_at)
VALUES (:source_key, :entity_type, :source_id, :target_id, NOW())
ON CONFLICT (source_key, entity_type, source_id)
DO UPDATE SET target_id = EXCLUDED.target_id, imported_at = NOW()
SQL,
            [
                'source_key' => $sourceKey,
                'entity_type' => $entityType,
                'source_id' => $sourceId,
                'target_id' => $targetId->toRfc4122(),
            ],
        );
    }
}
