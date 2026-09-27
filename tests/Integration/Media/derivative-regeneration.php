<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaDerivativeRepository;
use Mediarama\Media\Infrastructure\Persistence\PostgresMediaDerivativeRegenerationLock;
use Symfony\Component\Uid\Uuid;

function requireRegeneration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function derivative(
    Uuid $mediaId,
    string $profile,
    int $version,
    ?string $storageKey = null,
): MediaDerivative {
    $now = new DateTimeImmutable();

    return new MediaDerivative(
        Uuid::v7(),
        $mediaId,
        'image',
        $profile,
        $version,
        new StorageObjectId(
            'media',
            $storageKey ?? sprintf(
                'derivatives/%s/v%d/%s.webp',
                $mediaId->toRfc4122(),
                $version,
                $profile,
            ),
        ),
        'image/webp',
        123,
        64,
        64,
        null,
        [],
        $now,
        $now,
    );
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);

$params = $dsn->parse((string) getenv('DATABASE_URL'));
$db1 = DriverManager::getConnection($params);
$db2 = DriverManager::getConnection($params);

$mediaId = Uuid::v7();
$now = (new DateTimeImmutable())->format(DATE_ATOM);

try {
    $db1->insert('media_assets', [
        'id' => $mediaId->toRfc4122(),
        'owner_id' => null,
        'storage_disk' => 'media',
        'storage_key' => 'originals/regeneration/'.$mediaId->toRfc4122().'.jpg',
        'original_filename' => 'regeneration.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 123,
        'checksum_sha256' => str_repeat('a', 64),
        'width' => 64,
        'height' => 64,
        'duration_ms' => null,
        'title' => 'Derivative regeneration integration fixture',
        'description' => null,
        'captured_at' => null,
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);

    $repository = new DbalMediaDerivativeRepository($db1);

    $repository->saveAll([
        derivative($mediaId, 'thumbnail', 1),
        derivative($mediaId, 'preview', 1),
        derivative($mediaId, 'large', 1),
    ]);

    requireRegeneration(
        $repository->latestProcessingVersion($mediaId, 'image') === 1,
        'latest image processing version is read from persisted derivatives',
    );

    $versionOneCount = (int) $db1->fetchOne(
        'SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media AND kind = :kind AND processing_version = 1',
        ['media' => $mediaId->toRfc4122(), 'kind' => 'image'],
    );
    requireRegeneration($versionOneCount === 3, 'complete derivative set is persisted');

    $duplicateStorage = sprintf(
        'derivatives/%s/v2/shared.webp',
        $mediaId->toRfc4122(),
    );

    $invalidBatchRejected = false;

    try {
        $repository->saveAll([
            derivative($mediaId, 'thumbnail', 2, $duplicateStorage),
            derivative($mediaId, 'preview', 2, $duplicateStorage),
        ]);
    } catch (Throwable) {
        $invalidBatchRejected = true;
    }

    requireRegeneration(
        $invalidBatchRejected,
        'invalid derivative batch is rejected by the database uniqueness boundary',
    );

    $versionTwoCount = (int) $db1->fetchOne(
        'SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media AND kind = :kind AND processing_version = 2',
        ['media' => $mediaId->toRfc4122(), 'kind' => 'image'],
    );
    requireRegeneration(
        $versionTwoCount === 0,
        'failed derivative batch is rolled back atomically',
    );

    $lock1 = new PostgresMediaDerivativeRegenerationLock($db1);
    $lock2 = new PostgresMediaDerivativeRegenerationLock($db2);

    $lock1->synchronized(
        $mediaId,
        'image',
        function () use ($lock2, $mediaId): void {
            try {
                $lock2->synchronized($mediaId, 'image', static fn (): null => null);
                throw new RuntimeException('Concurrent image regeneration unexpectedly acquired the same lock.');
            } catch (DomainException $error) {
                requireRegeneration(
                    str_contains($error->getMessage(), 'already running'),
                    'concurrent regeneration of the same media/kind is rejected',
                );
            }

            $videoResult = $lock2->synchronized(
                $mediaId,
                'video',
                static fn (): string => 'video-lock-ok',
            );
            requireRegeneration(
                $videoResult === 'video-lock-ok',
                'regeneration lock is scoped by media and derivative kind',
            );
        },
    );

    $afterRelease = $lock2->synchronized(
        $mediaId,
        'image',
        static fn (): string => 'image-lock-released',
    );
    requireRegeneration(
        $afterRelease === 'image-lock-released',
        'regeneration advisory lock is released after the operation',
    );

    echo "Derivative regeneration persistence/locking integration checks passed.".PHP_EOL;
} finally {
    $db1->executeStatement(
        'DELETE FROM media_assets WHERE id = :id',
        ['id' => $mediaId->toRfc4122()],
    );

    $db1->close();
    $db2->close();
}
