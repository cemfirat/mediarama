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
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

function requireRegeneration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}


function removeRegenerationTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

/** @param list<string> $command */
function runRegenerationCommand(array $command): string
{
    $process = new Process($command);
    $process->setTimeout(60.0);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            "Command failed (%s): %s\n%s",
            implode(' ', $command),
            trim($process->getErrorOutput()),
            trim($process->getOutput()),
        ));
    }

    return $process->getOutput();
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
$mediaRoot = rtrim((string) getenv('MEDIA_STORAGE_PATH'), DIRECTORY_SEPARATOR);
$sourceRelative = 'originals/regeneration/'.$mediaId->toRfc4122().'.jpg';
$sourcePath = $mediaRoot.DIRECTORY_SEPARATOR.$sourceRelative;
$derivativeRoot = $mediaRoot.DIRECTORY_SEPARATOR.'derivatives'.DIRECTORY_SEPARATOR.$mediaId->toRfc4122();

if ($mediaRoot === '') {
    throw new RuntimeException('MEDIA_STORAGE_PATH must be configured.');
}

$sourceDirectory = dirname($sourcePath);
if (!is_dir($sourceDirectory) && !mkdir($sourceDirectory, 0770, true) && !is_dir($sourceDirectory)) {
    throw new RuntimeException('Unable to create regeneration source directory.');
}

$convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
if ($convertBinary === '') {
    throw new RuntimeException('IMAGEMAGICK_BINARY must be configured.');
}

runRegenerationCommand([
    $convertBinary,
    '-size',
    '120x80',
    'xc:white',
    $sourcePath,
]);

$sourceSize = filesize($sourcePath);
$sourceChecksum = hash_file('sha256', $sourcePath);
if ($sourceSize === false || $sourceChecksum === false) {
    throw new RuntimeException('Unable to inspect regeneration source fixture.');
}

try {
    $db1->insert('media_assets', [
        'id' => $mediaId->toRfc4122(),
        'owner_id' => null,
        'storage_disk' => 'media',
        'storage_key' => $sourceRelative,
        'original_filename' => 'regeneration.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => $sourceSize,
        'checksum_sha256' => $sourceChecksum,
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

    $firstOutput = runRegenerationCommand([
        PHP_BINARY,
        dirname(__DIR__, 3).'/bin/console',
        'mediarama:media:regenerate',
        $mediaId->toRfc4122(),
        '--no-interaction',
    ]);
    requireRegeneration(
        str_contains($firstOutput, 'processing version 2'),
        'console regeneration publishes a complete next-version derivative set',
    );

    $versionTwoProfiles = $db1->fetchFirstColumn(
        <<<'SQL'
SELECT profile
FROM media_derivatives
WHERE media_id = :media
  AND kind = 'image'
  AND processing_version = 2
ORDER BY profile
SQL,
        ['media' => $mediaId->toRfc4122()],
    );
    requireRegeneration(
        $versionTwoProfiles === ['large', 'preview', 'thumbnail'],
        'console regeneration persisted every configured image profile at version 2',
    );

    foreach (['thumbnail', 'preview', 'large'] as $profile) {
        requireRegeneration(
            is_file($derivativeRoot.'/v2/'.$profile.'.webp'),
            'version 2 '.$profile.' storage object exists',
        );
    }

    $secondOutput = runRegenerationCommand([
        PHP_BINARY,
        dirname(__DIR__, 3).'/bin/console',
        'mediarama:media:regenerate',
        $mediaId->toRfc4122(),
        '--no-interaction',
    ]);
    requireRegeneration(
        str_contains($secondOutput, 'processing version 3'),
        'repeated console regeneration advances to another immutable version',
    );

    $versionThreeCount = (int) $db1->fetchOne(
        'SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media AND kind = :kind AND processing_version = 3',
        ['media' => $mediaId->toRfc4122(), 'kind' => 'image'],
    );
    requireRegeneration(
        $versionThreeCount === 3,
        'second console regeneration persisted a complete version 3 set',
    );
    requireRegeneration(
        is_file($derivativeRoot.'/v2/thumbnail.webp')
        && is_file($derivativeRoot.'/v3/thumbnail.webp'),
        'older versioned derivative files remain addressable after regeneration',
    );

    echo "Derivative regeneration persistence/locking/CLI integration checks passed.".PHP_EOL;
} finally {
    $db1->executeStatement(
        'DELETE FROM media_assets WHERE id = :id',
        ['id' => $mediaId->toRfc4122()],
    );

    $db1->close();
    $db2->close();

    @unlink($sourcePath);
    removeRegenerationTree($derivativeRoot);
}
