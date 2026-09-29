<?php

declare(strict_types=1);

namespace Mediarama\Import\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Mediarama\Import\Application\ImportCheckpointRepository;

final readonly class DbalImportCheckpointRepository implements ImportCheckpointRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(string $sourceKey, string $stage): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT cursor FROM import_checkpoints WHERE source_key = :source_key AND stage = :stage',
            ['source_key' => $sourceKey, 'stage' => $stage],
        );

        return $value === false ? null : (string) $value;
    }

    public function save(string $sourceKey, string $stage, string $cursor): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO import_checkpoints (source_key, stage, cursor, updated_at)
VALUES (:source_key, :stage, :cursor, NOW())
ON CONFLICT (source_key, stage)
DO UPDATE SET cursor = EXCLUDED.cursor, updated_at = NOW()
SQL,
            ['source_key' => $sourceKey, 'stage' => $stage, 'cursor' => $cursor],
        );
    }

    public function clear(string $sourceKey, string $stage): void
    {
        $this->connection->delete('import_checkpoints', ['source_key' => $sourceKey, 'stage' => $stage]);
    }
}
