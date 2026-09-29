<?php

declare(strict_types=1);

namespace Mediarama\Tests\Integration\Import;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Import\Infrastructure\Persistence\DbalImportCheckpointRepository;
use Mediarama\Import\Infrastructure\Persistence\DbalImportMappingRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ImportSourceScopeRepositoryTest extends TestCase
{
    public function testSameSourceIdsAreIsolatedBySourceKey(): void
    {
        $parser = new DsnParser([
            'postgresql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
        ]);
        $connection = DriverManager::getConnection($parser->parse((string) getenv('DATABASE_URL')));
        $mappings = new DbalImportMappingRepository($connection);
        $checkpoints = new DbalImportCheckpointRepository($connection);

        $sourceA = 'coppermine:repository-test-a';
        $sourceB = 'coppermine:repository-test-b';
        $targetA = Uuid::v7();
        $targetB = Uuid::v7();

        try {
            $mappings->remember($sourceA, 'picture', '100', $targetA);
            $mappings->remember($sourceB, 'picture', '100', $targetB);
            $checkpoints->save($sourceA, 'pictures', '100');
            $checkpoints->save($sourceB, 'pictures', '200');

            self::assertSame($targetA->toRfc4122(), $mappings->findTargetId($sourceA, 'picture', '100')?->toRfc4122());
            self::assertSame($targetB->toRfc4122(), $mappings->findTargetId($sourceB, 'picture', '100')?->toRfc4122());
            self::assertSame('100', $checkpoints->get($sourceA, 'pictures'));
            self::assertSame('200', $checkpoints->get($sourceB, 'pictures'));
        } finally {
            $connection->executeStatement(
                'DELETE FROM import_mappings WHERE source_key IN (:a, :b)',
                ['a' => $sourceA, 'b' => $sourceB],
            );
            $connection->executeStatement(
                'DELETE FROM import_checkpoints WHERE source_key IN (:a, :b)',
                ['a' => $sourceA, 'b' => $sourceB],
            );
        }
    }
}
