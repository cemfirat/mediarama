<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Application\InspectImageFileGeometry;
use Mediarama\Media\Application\ProcessMedia;
use Mediarama\Media\Application\ValidateStoredMediaStructure;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Image\ImageMagickFileGeometryInspector;
use Mediarama\Media\Infrastructure\Image\ImageMagickProcess;
use Mediarama\Media\Infrastructure\Image\ImageMagickResourceLimits;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaAssetRepository;
use Mediarama\Media\Infrastructure\Probe\FfprobeProcess;
use Mediarama\Media\Infrastructure\Probe\LocalStoredMediaStructureValidator;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Mediarama\Upload\Application\FinalizeUpload;
use Mediarama\Upload\Application\UploadContentPolicy;
use Mediarama\Upload\Application\UploadDestinationAuthorizer;
use Mediarama\Upload\Infrastructure\LocalContentInspector;
use Mediarama\Upload\Infrastructure\Persistence\DbalUploadFinalizationCriticalSection;
use Mediarama\Upload\Infrastructure\Persistence\DbalUploadFinalizationRepository;
use Mediarama\Upload\Infrastructure\Persistence\DbalUploadSessionRepository;
use Mediarama\Upload\Domain\UploadSession;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\PostgreSqlConnection as DoctrineMessengerPostgreSqlConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function databaseConnection(): DbalConnection
{
    $url = trim((string) getenv('DATABASE_URL'));
    requireCondition($url !== '', 'DATABASE_URL must be configured.');

    $parser = new DsnParser([
        'postgresql' => 'pdo_pgsql',
        'postgres' => 'pdo_pgsql',
    ]);

    return DriverManager::getConnection($parser->parse($url));
}

function removeTree(string $path): void
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

/** @param list<string> $arguments */
function runTool(array $arguments): void
{
    $process = new Process($arguments);
    $process->setTimeout(30.0);
    $process->mustRun();
}

function doctrineTransport(DbalConnection $db): DoctrineTransport
{
    $configuration = DoctrineMessengerPostgreSqlConnection::buildConfiguration(
        'doctrine://default?queue_name=async&auto_setup=false',
    );

    return new DoctrineTransport(
        new DoctrineMessengerPostgreSqlConnection($configuration, $db),
        new PhpSerializer(),
    );
}

function doctrineTransportBus(DbalConnection $db): MessageBusInterface
{
    $transport = doctrineTransport($db);

    return new readonly class($transport) implements MessageBusInterface {
        public function __construct(private DoctrineTransport $transport)
        {
        }

        public function dispatch(object $message, array $stamps = []): Envelope
        {
            return $this->transport->send(new Envelope($message, $stamps));
        }
    };
}

function allowAnyDestination(): UploadDestinationAuthorizer
{
    return new class implements UploadDestinationAuthorizer {
        public function assertCanUpload(Uuid $userId, ?Uuid $collectionId): void
        {
            if ($collectionId !== null) {
                throw new LogicException('Concurrency fixture expects a library-root upload.');
            }
        }
    };
}

function mediaStructureValidator(
    LocalMediaStorage $storage,
    ?string $barrierDirectory = null,
    ?string $workerName = null,
): ValidateStoredMediaStructure {
    $convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
    $identifyBinary = trim((string) getenv('IMAGEMAGICK_IDENTIFY_BINARY'));
    $ffprobeBinary = trim((string) getenv('FFPROBE_BINARY'));

    requireCondition($convertBinary !== '', 'IMAGEMAGICK_BINARY must be configured.');
    requireCondition($identifyBinary !== '', 'IMAGEMAGICK_IDENTIFY_BINARY must be configured.');
    requireCondition($ffprobeBinary !== '', 'FFPROBE_BINARY must be configured.');

    $imageProcess = new ImageMagickProcess(
        new ImageMagickResourceLimits(),
        $convertBinary,
        $identifyBinary,
        30.0,
    );
    $delegate = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector($imageProcess),
        new FfprobeProcess($ffprobeBinary, 15.0, 33554432, 5000000),
    );

    if ($barrierDirectory === null || $workerName === null) {
        return $delegate;
    }

    return new readonly class($delegate, $barrierDirectory, $workerName) implements ValidateStoredMediaStructure {
        public function __construct(
            private ValidateStoredMediaStructure $delegate,
            private string $directory,
            private string $worker,
        ) {
        }

        public function __invoke(StorageObjectId $object, MediaType $mediaType): void
        {
            ($this->delegate)($object, $mediaType);

            $own = $this->directory.'/validated-'.$this->worker;
            $peerName = $this->worker === 'a' ? 'b' : 'a';
            $peer = $this->directory.'/validated-'.$peerName;

            if (file_put_contents($own, "ready\n", LOCK_EX) === false) {
                throw new RuntimeException('Unable to publish worker validation barrier.');
            }

            $deadline = microtime(true) + 15.0;
            while (!is_file($peer)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for peer finalizer validation.');
                }

                usleep(20000);
            }
        }
    };
}

function finalizer(
    DbalConnection $db,
    string $storageRoot,
    ?string $barrierDirectory = null,
    ?string $workerName = null,
): FinalizeUpload {
    $storage = new LocalMediaStorage($storageRoot);

    return new FinalizeUpload(
        new DbalUploadSessionRepository($db),
        new DbalMediaAssetRepository($db),
        $storage,
        new LocalContentInspector($storage),
        new UploadContentPolicy(['image/png']),
        mediaStructureValidator($storage, $barrierDirectory, $workerName),
        allowAnyDestination(),
        new DbalUploadFinalizationRepository($db),
        new DbalUploadFinalizationCriticalSection($db),
        doctrineTransportBus($db),
    );
}

function writeFixtureToSession(
    LocalMediaStorage $storage,
    UploadSession $session,
    string $fixturePath,
): void {
    $stream = fopen($fixturePath, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open upload finalization fixture.');
    }

    try {
        $storage->write(
            new StorageObjectId('media', $session->temporaryStorageKey),
            $stream,
            'image/png',
        );
    } finally {
        fclose($stream);
    }
}

function createUploadedSession(
    DbalConnection $db,
    LocalMediaStorage $storage,
    Uuid $userId,
    string $fixturePath,
    string $filename,
): UploadSession {
    $size = filesize($fixturePath);
    if ($size === false) {
        throw new RuntimeException('Unable to stat upload finalization fixture.');
    }

    $session = UploadSession::create(
        $userId,
        null,
        $filename,
        $size,
        'image/png',
    );
    $session->markUploaded();

    (new DbalUploadSessionRepository($db))->save($session);
    writeFixtureToSession($storage, $session, $fixturePath);

    return $session;
}

function queueCount(DbalConnection $db): int
{
    return (int) $db->fetchOne(
        "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'",
    );
}

function verifyCompletedScenario(
    DbalConnection $db,
    LocalMediaStorage $storage,
    UploadSession $session,
    int $expectedQueueCount,
): void {
    $id = $session->id->toRfc4122();
    $sessionRow = $db->fetchAssociative(
        'SELECT status, temporary_storage_key FROM upload_sessions WHERE id = :id',
        ['id' => $id],
    );
    requireCondition($sessionRow !== false, 'Upload session disappeared.');
    requireCondition($sessionRow['status'] === 'completed', 'Upload session did not reach completed.');

    requireCondition(
        (int) $db->fetchOne('SELECT COUNT(*) FROM media_assets WHERE id = :id', ['id' => $id]) === 1,
        'Expected exactly one deterministic MediaAsset.',
    );
    requireCondition(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM upload_finalizations WHERE upload_session_id = :id AND media_id = :id',
            ['id' => $id],
        ) === 1,
        'Expected exactly one upload finalization mapping.',
    );
    requireCondition(
        queueCount($db) === $expectedQueueCount,
        sprintf('Expected async queue count %d, got %d.', $expectedQueueCount, queueCount($db)),
    );

    $temporary = new StorageObjectId('media', (string) $sessionRow['temporary_storage_key']);
    $permanent = new StorageObjectId('media', sprintf('originals/%s/source', $id));
    requireCondition(!$storage->exists($temporary), 'Temporary upload still exists after finalization.');
    requireCondition($storage->exists($permanent), 'Deterministic immutable original is missing.');
}

function runWorker(array $argv): never
{
    if (count($argv) !== 7) {
        fwrite(STDERR, "Invalid worker arguments.\n");
        exit(2);
    }

    [, , $workerName, $sessionId, $userId, $storageRoot, $barrierDirectory] = $argv;
    $db = databaseConnection();

    try {
        $asset = finalizer($db, $storageRoot, $barrierDirectory, $workerName)(
            Uuid::fromString($sessionId),
            Uuid::fromString($userId),
        );

        fwrite(STDOUT, $asset->id->toRfc4122()."\n");
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
        exit(1);
    } finally {
        $db->close();
    }
}

if (($argv[1] ?? null) === 'worker') {
    runWorker($argv);
}

$db = databaseConnection();
$storageRoot = sys_get_temp_dir().'/mediarama-finalize-'.bin2hex(random_bytes(8));
$barrierDirectory = $storageRoot.'/barrier';
$fixtureDirectory = $storageRoot.'/fixtures';
$fixturePath = $fixtureDirectory.'/valid.png';
$storage = new LocalMediaStorage($storageRoot);
$createdSessionIds = [];
$userId = Uuid::v7();
$username = 'finalize-fixture-'.substr(str_replace('-', '', $userId->toRfc4122()), 0, 12);

try {
    if (!mkdir($barrierDirectory, 0700, true) && !is_dir($barrierDirectory)) {
        throw new RuntimeException('Unable to create finalization barrier directory.');
    }
    if (!mkdir($fixtureDirectory, 0700, true) && !is_dir($fixtureDirectory)) {
        throw new RuntimeException('Unable to create finalization fixture directory.');
    }

    $convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
    requireCondition($convertBinary !== '', 'IMAGEMAGICK_BINARY must be configured.');
    runTool([$convertBinary, '-size', '64x64', 'xc:white', $fixturePath]);

    $now = new DateTimeImmutable();
    $db->insert('users', [
        'id' => $userId->toRfc4122(),
        'username' => $username,
        'email' => null,
        'password_hash' => null,
        'display_name' => 'Finalize Fixture',
        'status' => 'active',
        'locale' => 'en',
        'created_at' => $now->format(DATE_ATOM),
        'updated_at' => $now->format(DATE_ATOM),
        'last_login_at' => null,
    ]);

    // The concurrency test owns the async queue during this CI phase. Earlier
    // upload/image integration tests do not enqueue processing jobs.
    $db->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'async'");
    requireCondition(queueCount($db) === 0, 'Async queue was not empty before finalization test.');

    // 1. Deterministic simultaneous-finalize race.
    $concurrent = createUploadedSession(
        $db,
        $storage,
        $userId,
        $fixturePath,
        'concurrent.png',
    );
    $createdSessionIds[] = $concurrent->id;

    $workerA = new Process([
        PHP_BINARY,
        __FILE__,
        'worker',
        'a',
        $concurrent->id->toRfc4122(),
        $userId->toRfc4122(),
        $storageRoot,
        $barrierDirectory,
    ]);
    $workerB = new Process([
        PHP_BINARY,
        __FILE__,
        'worker',
        'b',
        $concurrent->id->toRfc4122(),
        $userId->toRfc4122(),
        $storageRoot,
        $barrierDirectory,
    ]);
    $workerA->setTimeout(45.0);
    $workerB->setTimeout(45.0);
    $workerA->start();
    $workerB->start();

    $exitA = $workerA->wait();
    $exitB = $workerB->wait();

    requireCondition(
        $exitA === 0,
        'Concurrent finalizer A failed: '.trim($workerA->getErrorOutput()),
    );
    requireCondition(
        $exitB === 0,
        'Concurrent finalizer B failed: '.trim($workerB->getErrorOutput()),
    );

    $returnedA = trim($workerA->getOutput());
    $returnedB = trim($workerB->getOutput());
    requireCondition($returnedA === $concurrent->id->toRfc4122(), 'Worker A returned a non-deterministic MediaAsset id.');
    requireCondition($returnedB === $concurrent->id->toRfc4122(), 'Worker B returned a non-deterministic MediaAsset id.');
    requireCondition($returnedA === $returnedB, 'Concurrent finalizers returned different MediaAssets.');
    verifyCompletedScenario($db, $storage, $concurrent, 1);

    // 2. Crash immediately before filesystem promotion: finalizing + temp exists.
    $beforePromotion = createUploadedSession(
        $db,
        $storage,
        $userId,
        $fixturePath,
        'before-promotion.png',
    );
    $createdSessionIds[] = $beforePromotion->id;
    $beforePromotion->beginFinalization();
    (new DbalUploadSessionRepository($db))->save($beforePromotion);

    $asset = finalizer($db, $storageRoot)($beforePromotion->id, $userId);
    requireCondition($asset->id->equals($beforePromotion->id), 'Pre-promotion recovery changed MediaAsset identity.');
    verifyCompletedScenario($db, $storage, $beforePromotion, 2);

    // 3. Crash after filesystem promotion but before MediaAsset persistence.
    $afterPromotion = createUploadedSession(
        $db,
        $storage,
        $userId,
        $fixturePath,
        'after-promotion.png',
    );
    $createdSessionIds[] = $afterPromotion->id;
    $afterPromotion->beginFinalization();
    (new DbalUploadSessionRepository($db))->save($afterPromotion);
    $afterPromotionPermanent = new StorageObjectId(
        'media',
        sprintf('originals/%s/source', $afterPromotion->id->toRfc4122()),
    );
    $storage->promote(
        new StorageObjectId('media', $afterPromotion->temporaryStorageKey),
        $afterPromotionPermanent,
    );

    $asset = finalizer($db, $storageRoot)($afterPromotion->id, $userId);
    requireCondition($asset->id->equals($afterPromotion->id), 'Post-promotion recovery changed MediaAsset identity.');
    verifyCompletedScenario($db, $storage, $afterPromotion, 3);

    // 4. Crash after MediaAsset persistence but before finalization mapping.
    $afterMedia = createUploadedSession(
        $db,
        $storage,
        $userId,
        $fixturePath,
        'after-media.png',
    );
    $createdSessionIds[] = $afterMedia->id;
    $afterMedia->beginFinalization();
    (new DbalUploadSessionRepository($db))->save($afterMedia);
    $afterMediaPermanent = new StorageObjectId(
        'media',
        sprintf('originals/%s/source', $afterMedia->id->toRfc4122()),
    );
    $storedAfterMedia = $storage->promote(
        new StorageObjectId('media', $afterMedia->temporaryStorageKey),
        $afterMediaPermanent,
    );
    $inspectedAfterMedia = (new LocalContentInspector($storage))->inspect($afterMediaPermanent);
    (new DbalMediaAssetRepository($db))->save(MediaAsset::createWithId(
        $afterMedia->id,
        $userId,
        $afterMediaPermanent,
        $afterMedia->originalFilename,
        $inspectedAfterMedia->mimeType,
        $inspectedAfterMedia->mediaType,
        $storedAfterMedia->byteSize,
        $inspectedAfterMedia->sha256,
    ));

    $asset = finalizer($db, $storageRoot)($afterMedia->id, $userId);
    requireCondition($asset->id->equals($afterMedia->id), 'Post-MediaAsset recovery changed MediaAsset identity.');
    verifyCompletedScenario($db, $storage, $afterMedia, 4);

    // 5. Legacy crash after mapping but before session completion/dispatch.
    $afterMapping = createUploadedSession(
        $db,
        $storage,
        $userId,
        $fixturePath,
        'after-mapping.png',
    );
    $createdSessionIds[] = $afterMapping->id;
    $afterMapping->beginFinalization();
    (new DbalUploadSessionRepository($db))->save($afterMapping);
    $afterMappingPermanent = new StorageObjectId(
        'media',
        sprintf('originals/%s/source', $afterMapping->id->toRfc4122()),
    );
    $storedAfterMapping = $storage->promote(
        new StorageObjectId('media', $afterMapping->temporaryStorageKey),
        $afterMappingPermanent,
    );
    $inspectedAfterMapping = (new LocalContentInspector($storage))->inspect($afterMappingPermanent);
    $mappedAsset = MediaAsset::createWithId(
        $afterMapping->id,
        $userId,
        $afterMappingPermanent,
        $afterMapping->originalFilename,
        $inspectedAfterMapping->mimeType,
        $inspectedAfterMapping->mediaType,
        $storedAfterMapping->byteSize,
        $inspectedAfterMapping->sha256,
    );
    (new DbalMediaAssetRepository($db))->save($mappedAsset);
    (new DbalUploadFinalizationRepository($db))->remember($afterMapping->id, $mappedAsset->id);

    $asset = finalizer($db, $storageRoot)($afterMapping->id, $userId);
    requireCondition($asset->id->equals($afterMapping->id), 'Post-mapping legacy recovery changed MediaAsset identity.');
    verifyCompletedScenario($db, $storage, $afterMapping, 5);

    // 6. Prove Symfony Doctrine Messenger's nested send does not escape
    // the outer finalization transaction on the shared DBAL connection.
    $queueBeforeRollbackProbe = queueCount($db);
    $transport = doctrineTransport($db);
    $critical = new DbalUploadFinalizationCriticalSection($db);
    $rollbackObserved = false;

    try {
        $critical->run(
            $afterMapping->id,
            static function () use ($transport, $afterMapping): void {
                $transport->send(new Envelope(new ProcessMedia($afterMapping->id->toRfc4122())));
                throw new RuntimeException('intentional messenger rollback probe');
            },
        );
    } catch (RuntimeException $error) {
        requireCondition(
            $error->getMessage() === 'intentional messenger rollback probe',
            'Unexpected messenger rollback-probe failure: '.$error->getMessage(),
        );
        $rollbackObserved = true;
    }

    requireCondition($rollbackObserved, 'Messenger rollback probe did not throw as expected.');

    $visibilityConnection = databaseConnection();
    try {
        requireCondition(
            queueCount($visibilityConnection) === $queueBeforeRollbackProbe,
            'Doctrine Messenger queue insert escaped the outer transaction.',
        );
    } finally {
        $visibilityConnection->close();
    }

    echo "OK concurrent finalizers converge on one MediaAsset\n";
    echo "OK exactly one immutable original and one mapping\n";
    echo "OK exactly one processing dispatch for the race\n";
    echo "OK retry before promotion\n";
    echo "OK retry after promotion before MediaAsset persistence\n";
    echo "OK retry after MediaAsset persistence before mapping\n";
    echo "OK legacy mapping/session recovery dispatch\n";
    echo "OK Doctrine Messenger enqueue rolls back with finalization transaction\n";
} finally {
    $db->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'async'");

    foreach (array_reverse($createdSessionIds) as $sessionId) {
        $id = $sessionId->toRfc4122();
        $db->executeStatement('DELETE FROM upload_finalizations WHERE upload_session_id = :id', ['id' => $id]);
        $db->executeStatement('DELETE FROM upload_sessions WHERE id = :id', ['id' => $id]);
        $db->executeStatement('DELETE FROM media_assets WHERE id = :id', ['id' => $id]);
    }

    $db->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId->toRfc4122()]);
    $db->close();
    removeTree($storageRoot);
}
