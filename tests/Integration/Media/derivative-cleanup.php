<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Persistence\DbalDerivativeCleanupRepository;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaDerivativeRepository;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

function requireCleanup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

/** @param list<string> $command */
function runCleanupCommand(array $command): string
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

function removeCleanupTree(string $path): void
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

function insertCleanupMedia(Doctrine\DBAL\Connection $db, Uuid $id, string $now): void
{
    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => null,
        'storage_disk' => 'media',
        'storage_key' => 'originals/cleanup/'.$id->toRfc4122().'/source',
        'original_filename' => 'cleanup.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => str_repeat('a', 64),
        'width' => 1,
        'height' => 1,
        'duration_ms' => null,
        'title' => 'Derivative cleanup integration fixture',
        'description' => null,
        'captured_at' => null,
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

function cleanupDerivative(
    Uuid $mediaId,
    string $profile,
    int $version,
    DateTimeImmutable $createdAt,
    int $byteSize,
    ?string $storageKey = null,
): MediaDerivative {
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
        $byteSize,
        1,
        1,
        null,
        [],
        $createdAt,
        $createdAt,
    );
}

function writeCleanupObject(LocalMediaStorage $storage, StorageObjectId $id, string $payload): void
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Unable to create cleanup storage fixture stream.');
    }

    try {
        fwrite($stream, $payload);
        rewind($stream);
        $storage->write($id, $stream, 'image/webp');
    } finally {
        fclose($stream);
    }
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);

$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$mediaRoot = rtrim((string) getenv('MEDIA_STORAGE_PATH'), DIRECTORY_SEPARATOR);
if ($mediaRoot === '') {
    throw new RuntimeException('MEDIA_STORAGE_PATH must be configured.');
}

$storage = new LocalMediaStorage($mediaRoot);
$derivatives = new DbalMediaDerivativeRepository($db);
$cleanupRepository = new DbalDerivativeCleanupRepository($db);
$mediaId = Uuid::v7();
$guardMediaId = Uuid::v7();
$now = (new DateTimeImmutable())->format(DATE_ATOM);
$old = new DateTimeImmutable('-500 days');
$cutoff = new DateTimeImmutable('-400 days');
$derivativeRoot = $mediaRoot.DIRECTORY_SEPARATOR.'derivatives'.DIRECTORY_SEPARATOR.$mediaId->toRfc4122();

try {
    insertCleanupMedia($db, $mediaId, $now);

    foreach (range(1, 5) as $version) {
        foreach (['thumbnail', 'preview', 'large'] as $profile) {
            $payload = sprintf('v%d-%s', $version, $profile);
            $derivative = cleanupDerivative(
                $mediaId,
                $profile,
                $version,
                $old,
                strlen($payload),
            );
            writeCleanupObject($storage, $derivative->storage, $payload);
            $derivatives->save($derivative);
        }
    }

    $preview = runCleanupCommand([
        PHP_BINARY,
        dirname(__DIR__, 3).'/bin/console',
        'mediarama:media:cleanup-derivatives',
        '--older-than-days=400',
        '--keep-versions=3',
        '--generation-limit=100',
        '--no-interaction',
    ]);
    requireCleanup(
        str_contains($preview, 'Preview only: 2 generation(s), 6 derivative object(s)'),
        'cleanup command defaults to non-mutating preview with generation-aware retention',
    );
    requireCleanup(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media',
            ['media' => $mediaId->toRfc4122()],
        ) === 15,
        'preview leaves derivative database rows untouched',
    );

    $queued = $cleanupRepository->enqueueSuperseded($cutoff, 3, 1);
    requireCleanup(
        $queued->generations === 1 && $queued->derivatives === 3,
        'one superseded generation can be transactionally queued',
    );
    requireCleanup(
        (int) $db->fetchOne('SELECT COUNT(*) FROM storage_cleanup_jobs') === 3,
        'queued storage debt survives independently after derivative rows are removed',
    );
    requireCleanup(
        is_file($derivativeRoot.'/v1/thumbnail.webp'),
        'simulated interruption leaves physical object for retryable cleanup',
    );

    $execute = runCleanupCommand([
        PHP_BINARY,
        dirname(__DIR__, 3).'/bin/console',
        'mediarama:media:cleanup-derivatives',
        '--execute',
        '--older-than-days=400',
        '--keep-versions=3',
        '--generation-limit=100',
        '--storage-limit=100',
        '--no-interaction',
    ]);
    requireCleanup(
        str_contains($execute, 'Queued 1 generation(s) / 3 derivative object(s)')
        && str_contains($execute, 'storage resolved=6 failed=0 pending=0'),
        'next cleanup run resumes queued storage debt and removes the remaining eligible generation',
    );

    $remainingVersions = array_map(
        'intval',
        $db->fetchFirstColumn(
            <<<'SQL'
SELECT DISTINCT processing_version
FROM media_derivatives
WHERE media_id = :media
ORDER BY processing_version
SQL,
            ['media' => $mediaId->toRfc4122()],
        ),
    );
    requireCleanup(
        $remainingVersions === [3, 4, 5],
        'latest three derivative generations are retained',
    );
    requireCleanup(
        !is_file($derivativeRoot.'/v1/thumbnail.webp')
        && !is_file($derivativeRoot.'/v2/thumbnail.webp')
        && is_file($derivativeRoot.'/v3/thumbnail.webp')
        && is_file($derivativeRoot.'/v5/thumbnail.webp'),
        'only eligible superseded derivative storage objects are deleted',
    );
    requireCleanup(
        (int) $db->fetchOne('SELECT COUNT(*) FROM storage_cleanup_jobs') === 0,
        'successful physical cleanup clears retryable storage debt',
    );

    $repeat = runCleanupCommand([
        PHP_BINARY,
        dirname(__DIR__, 3).'/bin/console',
        'mediarama:media:cleanup-derivatives',
        '--execute',
        '--older-than-days=400',
        '--keep-versions=3',
        '--generation-limit=100',
        '--storage-limit=100',
        '--no-interaction',
    ]);
    requireCleanup(
        str_contains($repeat, 'Queued 0 generation(s) / 0 derivative object(s)')
        && str_contains($repeat, 'storage resolved=0 failed=0 pending=0'),
        'repeated cleanup is idempotent',
    );

    insertCleanupMedia($db, $guardMediaId, $now);
    foreach (range(1, 4) as $version) {
        $derivatives->save(cleanupDerivative(
            $guardMediaId,
            'thumbnail',
            $version,
            $old,
            1,
            $version === 1 ? 'originals/not-owned-by-derivative-cleanup/source' : null,
        ));
    }

    $guardRejected = false;
    try {
        $cleanupRepository->enqueueSuperseded($cutoff, 3, 10);
    } catch (LogicException $error) {
        $guardRejected = str_contains($error->getMessage(), 'outside the owned generation prefix');
    }

    requireCleanup(
        $guardRejected,
        'cleanup refuses storage keys outside the deterministic Mediarama derivative prefix',
    );
    requireCleanup(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media',
            ['media' => $guardMediaId->toRfc4122()],
        ) === 4,
        'owned-prefix guard rolls the cleanup transaction back without deleting records',
    );
    requireCleanup(
        (int) $db->fetchOne('SELECT COUNT(*) FROM storage_cleanup_jobs') === 0,
        'owned-prefix guard leaves no partial cleanup queue',
    );

    echo "Superseded derivative cleanup integration checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM media_assets WHERE id IN (:first, :second)',
        [
            'first' => $mediaId->toRfc4122(),
            'second' => $guardMediaId->toRfc4122(),
        ],
    );
    $db->executeStatement(
        "DELETE FROM storage_cleanup_jobs WHERE reason = 'superseded_derivative'",
    );

    $db->close();
    removeCleanupTree($derivativeRoot);
    removeCleanupTree(
        $mediaRoot.DIRECTORY_SEPARATOR.'derivatives'.DIRECTORY_SEPARATOR.$guardMediaId->toRfc4122(),
    );
}
