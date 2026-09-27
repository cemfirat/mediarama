<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Application\CleanupSupersededDerivatives;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Persistence\DbalDerivativeCleanupRepository;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaDerivativeRepository;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Symfony\Component\Uid\Uuid;

function requireDerivativeCleanup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

/** @return resource */
function cleanupStream(string $contents)
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Unable to allocate cleanup fixture stream.');
    }

    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

function cleanupDerivative(
    Uuid $mediaId,
    string $profile,
    int $version,
    DateTimeImmutable $createdAt,
): MediaDerivative {
    return new MediaDerivative(
        Uuid::v7(),
        $mediaId,
        'image',
        $profile,
        $version,
        new StorageObjectId(
            'media',
            sprintf(
                'derivatives/%s/v%d/%s.webp',
                $mediaId->toRfc4122(),
                $version,
                $profile,
            ),
        ),
        'image/webp',
        32,
        64,
        64,
        null,
        [],
        $createdAt,
        $createdAt,
    );
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
$cleanup = new CleanupSupersededDerivatives(
    $cleanupRepository,
    $storage,
    400,
    2,
);

$mediaId = Uuid::v7();
$now = new DateTimeImmutable();
$rows = [];
$trackedStorage = [];

try {
    $db->insert('media_assets', [
        'id' => $mediaId->toRfc4122(),
        'owner_id' => null,
        'storage_disk' => 'media',
        'storage_key' => 'originals/'.$mediaId->toRfc4122().'/source',
        'original_filename' => 'cleanup-fixture.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 1,
        'checksum_sha256' => str_repeat('a', 64),
        'width' => 64,
        'height' => 64,
        'duration_ms' => null,
        'title' => 'Derivative cleanup integration fixture',
        'description' => null,
        'captured_at' => null,
        'processing_state' => 'ready',
        'moderation_state' => 'draft',
        'metadata' => '{}',
        'created_at' => $now->format(DATE_ATOM),
        'updated_at' => $now->format(DATE_ATOM),
        'deleted_at' => null,
    ]);

    $versions = [
        1 => new DateTimeImmutable('-900 days'),
        2 => new DateTimeImmutable('-800 days'),
        3 => new DateTimeImmutable('-10 days'),
        4 => new DateTimeImmutable('-1 day'),
    ];

    foreach ($versions as $version => $createdAt) {
        foreach (['thumbnail', 'preview'] as $profile) {
            $derivative = cleanupDerivative($mediaId, $profile, $version, $createdAt);
            $rows[] = $derivative;
            $trackedStorage[] = $derivative->storage;

            $stream = cleanupStream(sprintf('v%d-%s', $version, $profile));
            try {
                $storage->write($derivative->storage, $stream, 'image/webp');
            } finally {
                fclose($stream);
            }
        }
    }

    $derivatives->saveAll($rows);

    $staged = $cleanupRepository->stageSuperseded(
        new DateTimeImmutable('-400 days'),
        2,
        100,
    );

    requireDerivativeCleanup(
        $staged === 2,
        'only the fully superseded version outside the 400-day grace window is staged',
    );
    requireDerivativeCleanup(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media AND processing_version = 1',
            ['media' => $mediaId->toRfc4122()],
        ) === 0,
        'staging retires the old database records transactionally',
    );
    requireDerivativeCleanup(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM derivative_cleanup_jobs WHERE media_id = :media',
            ['media' => $mediaId->toRfc4122()],
        ) === 2,
        'every retired storage object has a durable cleanup job',
    );
    requireDerivativeCleanup(
        $storage->exists(new StorageObjectId(
            'media',
            sprintf('derivatives/%s/v1/thumbnail.webp', $mediaId->toRfc4122()),
        )),
        'a crash after SQL staging leaves the physical object recoverable through the queue',
    );

    $report = $cleanup(100, 500);
    requireDerivativeCleanup(
        $report->stagedObjects === 0
        && $report->completedObjects === 2
        && $report->failedObjects === 0,
        'the next cleanup run drains objects staged before an interruption',
    );
    requireDerivativeCleanup(
        !$storage->exists(new StorageObjectId(
            'media',
            sprintf('derivatives/%s/v1/thumbnail.webp', $mediaId->toRfc4122()),
        )),
        'staged superseded storage is deleted',
    );
    requireDerivativeCleanup(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media AND processing_version IN (2, 3, 4)',
            ['media' => $mediaId->toRfc4122()],
        ) === 6,
        'grace-window and two newest generations remain intact',
    );

    $again = $cleanup(100, 500);
    requireDerivativeCleanup(
        $again->stagedObjects === 0
        && $again->completedObjects === 0
        && $again->failedObjects === 0,
        'repeated cleanup is idempotent',
    );

    $orphan = cleanupDerivative(
        $mediaId,
        'thumbnail',
        99,
        new DateTimeImmutable('-2 days'),
    );
    $trackedStorage[] = $orphan->storage;
    $stream = cleanupStream('known failed-generation orphan');
    try {
        $storage->write($orphan->storage, $stream, 'image/webp');
    } finally {
        fclose($stream);
    }
    $cleanupRepository->enqueueOrphan($orphan);

    $orphanReport = $cleanup(100, 500);
    requireDerivativeCleanup(
        $orphanReport->completedObjects === 1
        && $orphanReport->failedObjects === 0
        && !$storage->exists($orphan->storage),
        'known failed-generation orphan objects are reconciled through the same durable queue',
    );

    $alreadyDeleted = cleanupDerivative(
        $mediaId,
        'preview',
        98,
        new DateTimeImmutable('-2 days'),
    );
    $cleanupRepository->enqueueOrphan($alreadyDeleted);

    $missingReport = $cleanup(100, 500);
    requireDerivativeCleanup(
        $missingReport->completedObjects === 1
        && $missingReport->failedObjects === 0,
        'a crash after physical deletion but before queue completion is safe to retry',
    );

    $protectedOriginal = new StorageObjectId(
        'media',
        'originals/'.$mediaId->toRfc4122().'/source',
    );
    $stream = cleanupStream('protected original');
    try {
        $storage->write($protectedOriginal, $stream, 'application/octet-stream');
    } finally {
        fclose($stream);
    }
    $trackedStorage[] = $protectedOriginal;

    $db->insert('derivative_cleanup_jobs', [
        'storage_disk' => 'media',
        'storage_key' => $protectedOriginal->key,
        'reason' => 'synthetic_scope_guard',
        'media_id' => $mediaId->toRfc4122(),
        'kind' => 'image',
        'profile' => 'thumbnail',
        'processing_version' => 1,
        'created_at' => $now->format(DATE_ATOM),
        'updated_at' => $now->format(DATE_ATOM),
    ]);

    $guardReport = $cleanup(100, 500);
    requireDerivativeCleanup(
        $guardReport->failedObjects === 1
        && $storage->exists($protectedOriginal),
        'cleanup refuses storage objects outside the Mediarama derivative prefix',
    );
    requireDerivativeCleanup(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM derivative_cleanup_jobs WHERE storage_key = :key',
            ['key' => $protectedOriginal->key],
        ) === 1,
        'an unsafe queued path remains visible for operator investigation instead of being silently discarded',
    );

    echo "Derivative retention and cleanup integration checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM derivative_cleanup_jobs WHERE media_id = :media',
        ['media' => $mediaId->toRfc4122()],
    );
    $db->executeStatement(
        'DELETE FROM media_assets WHERE id = :media',
        ['media' => $mediaId->toRfc4122()],
    );

    foreach ($trackedStorage as $object) {
        try {
            $storage->delete($object);
        } catch (Throwable) {
        }
    }

    $db->close();
}
