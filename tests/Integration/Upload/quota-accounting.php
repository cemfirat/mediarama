<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaAssetRepository;
use Mediarama\Upload\Application\CreateUploadSession;
use Mediarama\Upload\Application\UploadDestinationAuthorizer;
use Mediarama\Upload\Application\UploadPolicy;
use Mediarama\Upload\Application\UploadQuotaExceeded;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use Mediarama\Upload\Infrastructure\Persistence\DbalExpiredUploadSessionRepository;
use Mediarama\Upload\Infrastructure\Persistence\DbalUploadQuota;
use Mediarama\Upload\Infrastructure\Persistence\DbalUploadSessionRepository;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

function requireQuotaCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function quotaDatabase(): Connection
{
    $url = trim((string) getenv('DATABASE_URL'));
    requireQuotaCondition($url !== '', 'DATABASE_URL must be configured.');

    $parser = new DsnParser([
        'postgresql' => 'pdo_pgsql',
        'postgres' => 'pdo_pgsql',
    ]);

    return DriverManager::getConnection($parser->parse($url));
}

function quotaAuthorizer(): UploadDestinationAuthorizer
{
    return new class implements UploadDestinationAuthorizer {
        public function assertCanUpload(Uuid $userId, ?Uuid $collectionId): void
        {
            if ($collectionId !== null) {
                throw new LogicException('Quota fixture expects library-root uploads.');
            }
        }
    };
}

function quotaCreator(Connection $db, int $defaultQuotaBytes = 0): CreateUploadSession
{
    return new CreateUploadSession(
        new DbalUploadSessionRepository($db),
        quotaAuthorizer(),
        new UploadPolicy(1073741824, 16777216),
        new DbalUploadQuota($db, $defaultQuotaBytes),
    );
}

function insertQuotaUser(Connection $db, Uuid $id, string $suffix): void
{
    $now = new DateTimeImmutable();
    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => 'quota-fixture-'.$suffix,
        'email' => null,
        'password_hash' => null,
        'display_name' => 'Quota Fixture',
        'status' => 'active',
        'locale' => 'en',
        'created_at' => $now->format(DATE_ATOM),
        'updated_at' => $now->format(DATE_ATOM),
        'last_login_at' => null,
    ]);
}

function reservationCount(Connection $db, Uuid $userId): int
{
    return (int) $db->fetchOne(
        'SELECT COUNT(*) FROM upload_quota_reservations WHERE user_id = :user_id',
        ['user_id' => $userId->toRfc4122()],
    );
}

function sessionCount(Connection $db, Uuid $userId): int
{
    return (int) $db->fetchOne(
        'SELECT COUNT(*) FROM upload_sessions WHERE user_id = :user_id',
        ['user_id' => $userId->toRfc4122()],
    );
}

function removeQuotaTree(string $path): void
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

function waitForQuotaFile(string $path, float $timeoutSeconds = 10.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for quota barrier: '.$path);
        }
        usleep(20000);
    }
}

function quotaWorker(array $argv): never
{
    if (count($argv) !== 7) {
        fwrite(STDERR, "Invalid quota worker arguments.\n");
        exit(2);
    }

    [, , $name, $userIdValue, $bytesValue, $barrierDirectory, $defaultQuotaValue] = $argv;
    if (file_put_contents($barrierDirectory.'/'.$name.'.ready', "ready\n", LOCK_EX) === false) {
        fwrite(STDERR, "Unable to publish quota worker readiness.\n");
        exit(2);
    }

    waitForQuotaFile($barrierDirectory.'/go');
    $db = quotaDatabase();

    try {
        $session = quotaCreator($db, (int) $defaultQuotaValue)(
            Uuid::fromString($userIdValue),
            null,
            'concurrent-'.$name.'.bin',
            (int) $bytesValue,
            'application/octet-stream',
        );

        fwrite(STDOUT, $session->id->toRfc4122()."\n");
        exit(0);
    } catch (UploadQuotaExceeded) {
        fwrite(STDOUT, "QUOTA\n");
        exit(3);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
        exit(1);
    } finally {
        $db->close();
    }
}

if (($argv[1] ?? null) === 'worker') {
    quotaWorker($argv);
}

$db = quotaDatabase();
$userId = Uuid::v7();
$groupOne = Uuid::v7();
$groupTwo = Uuid::v7();
$barrierDirectory = sys_get_temp_dir().'/mediarama-quota-'.bin2hex(random_bytes(8));
$assetId = null;

try {
    if (!mkdir($barrierDirectory, 0700, true) && !is_dir($barrierDirectory)) {
        throw new RuntimeException('Unable to create quota concurrency barrier directory.');
    }

    insertQuotaUser($db, $userId, substr(str_replace('-', '', $userId->toRfc4122()), 0, 12));

    foreach ([[$groupOne, 'quota-one'], [$groupTwo, 'quota-two']] as [$groupId, $slug]) {
        $now = new DateTimeImmutable();
        $db->insert(
            'groups',
            [
                'id' => $groupId->toRfc4122(),
                'slug' => $slug.'-'.substr(str_replace('-', '', $userId->toRfc4122()), 0, 8),
                'name' => $slug,
                'is_system' => false,
                'created_at' => $now->format(DATE_ATOM),
                'updated_at' => $now->format(DATE_ATOM),
            ],
            ['is_system' => ParameterType::BOOLEAN],
        );
        $db->insert(
            'user_groups',
            [
                'user_id' => $userId->toRfc4122(),
                'group_id' => $groupId->toRfc4122(),
                'is_primary' => $groupId->equals($groupOne),
                'created_at' => $now->format(DATE_ATOM),
            ],
            ['is_primary' => ParameterType::BOOLEAN],
        );
    }

    // Parent lock makes the race deterministic: both workers must wait on the
    // same user row, then exactly one 600-byte reservation fits into 1000 bytes.
    $db->insert('user_storage_quotas', [
        'user_id' => $userId->toRfc4122(),
        'limit_bytes' => 1000,
        'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);

    $db->beginTransaction();
    $db->fetchOne(
        'SELECT id FROM users WHERE id = :user_id FOR UPDATE',
        ['user_id' => $userId->toRfc4122()],
    );

    $workerA = new Process([PHP_BINARY, __FILE__, 'worker', 'a', $userId->toRfc4122(), '600', $barrierDirectory, '0']);
    $workerB = new Process([PHP_BINARY, __FILE__, 'worker', 'b', $userId->toRfc4122(), '600', $barrierDirectory, '0']);
    $workerA->setTimeout(30.0);
    $workerB->setTimeout(30.0);
    $workerA->start();
    $workerB->start();

    waitForQuotaFile($barrierDirectory.'/a.ready');
    waitForQuotaFile($barrierDirectory.'/b.ready');
    file_put_contents($barrierDirectory.'/go', "go\n", LOCK_EX);
    usleep(300000);
    $db->commit();

    $exitA = $workerA->wait();
    $exitB = $workerB->wait();
    $exits = [$exitA, $exitB];
    sort($exits);

    requireQuotaCondition(
        $exits === [0, 3],
        sprintf(
            'Expected one concurrent success and one quota rejection. A=%d (%s) B=%d (%s)',
            $exitA,
            trim($workerA->getErrorOutput()),
            $exitB,
            trim($workerB->getErrorOutput()),
        ),
    );
    requireQuotaCondition(sessionCount($db, $userId) === 1, 'Concurrent quota test persisted wrong session count.');
    requireQuotaCondition(reservationCount($db, $userId) === 1, 'Concurrent quota test persisted wrong reservation count.');

    $db->executeStatement('DELETE FROM upload_sessions WHERE user_id = :user_id', ['user_id' => $userId->toRfc4122()]);
    requireQuotaCondition(reservationCount($db, $userId) === 0, 'Session delete did not cascade quota reservation.');

    // Explicit user policy wins even when one group is unlimited.
    $db->insert('group_storage_quotas', [
        'group_id' => $groupOne->toRfc4122(),
        'limit_bytes' => 0,
        'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
    $db->update('user_storage_quotas', ['limit_bytes' => 700], ['user_id' => $userId->toRfc4122()]);

    try {
        quotaCreator($db)($userId, null, 'user-override.bin', 800, null);
        throw new RuntimeException('Explicit user quota did not override unlimited group policy.');
    } catch (UploadQuotaExceeded $error) {
        requireQuotaCondition($error->limitBytes === 700, 'Unexpected explicit user quota limit.');
    }
    requireQuotaCondition(sessionCount($db, $userId) === 0, 'Quota rejection persisted an UploadSession.');
    requireQuotaCondition(reservationCount($db, $userId) === 0, 'Quota rejection persisted a reservation.');

    // Largest finite group quota wins without a user override.
    $db->delete('user_storage_quotas', ['user_id' => $userId->toRfc4122()]);
    $db->update('group_storage_quotas', ['limit_bytes' => 600], ['group_id' => $groupOne->toRfc4122()]);
    $db->insert('group_storage_quotas', [
        'group_id' => $groupTwo->toRfc4122(),
        'limit_bytes' => 900,
        'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);

    $finiteGroupSession = quotaCreator($db)($userId, null, 'group-max.bin', 800, null);
    requireQuotaCondition(reservationCount($db, $userId) === 1, 'Largest finite group quota did not permit reservation.');
    $db->delete('upload_sessions', ['id' => $finiteGroupSession->id->toRfc4122()]);

    // Any unlimited group makes effective group quota unlimited.
    $db->update('group_storage_quotas', ['limit_bytes' => 0], ['group_id' => $groupTwo->toRfc4122()]);
    $unlimitedGroupSession = quotaCreator($db)($userId, null, 'group-unlimited.bin', 5000, null);
    requireQuotaCondition(reservationCount($db, $userId) === 1, 'Unlimited group policy did not persist reservation.');
    $db->delete('upload_sessions', ['id' => $unlimitedGroupSession->id->toRfc4122()]);

    // No policy falls back to explicit deployment default; unlimited still accounts.
    $db->executeStatement(
        'DELETE FROM group_storage_quotas WHERE group_id IN (:one, :two)',
        ['one' => $groupOne->toRfc4122(), 'two' => $groupTwo->toRfc4122()],
    );
    $defaultSession = quotaCreator($db, 0)($userId, null, 'default-unlimited.bin', 123, null);
    $reserved = $db->fetchOne(
        'SELECT reserved_bytes FROM upload_quota_reservations WHERE upload_session_id = :id',
        ['id' => $defaultSession->id->toRfc4122()],
    );
    requireQuotaCondition((int) $reserved === 123, 'Default unlimited policy did not persist reservation.');
    $db->delete('upload_sessions', ['id' => $defaultSession->id->toRfc4122()]);

    // Persistence failure must roll back the reservation inserted before session save.
    $failedSessionId = Uuid::v7();
    try {
        (new DbalUploadQuota($db, 0))->reserve(
            $failedSessionId,
            $userId,
            50,
            static function (): void {
                throw new RuntimeException('intentional session persistence failure');
            },
        );
        throw new RuntimeException('Expected persistence callback failure.');
    } catch (RuntimeException $error) {
        requireQuotaCondition(
            $error->getMessage() === 'intentional session persistence failure',
            'Unexpected persistence-failure probe error.',
        );
    }
    requireQuotaCondition(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id',
            ['id' => $failedSessionId->toRfc4122()],
        ) === 0,
        'Failed session persistence left a reservation behind.',
    );

    // Existing originals are canonical committed usage. Soft-delete still counts
    // until physical purge because storage is still occupied.
    $db->insert('user_storage_quotas', [
        'user_id' => $userId->toRfc4122(),
        'limit_bytes' => 1000,
        'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
    $assetId = Uuid::v7();
    (new DbalMediaAssetRepository($db))->save(MediaAsset::createWithId(
        $assetId,
        $userId,
        new StorageObjectId('media', 'quota-fixture/'.$assetId->toRfc4122().'/source'),
        'existing.jpg',
        'image/jpeg',
        MediaType::Image,
        900,
        str_repeat('a', 64),
    ));

    foreach ([false, true] as $softDeleted) {
        if ($softDeleted) {
            $db->update(
                'media_assets',
                ['deleted_at' => (new DateTimeImmutable())->format(DATE_ATOM)],
                ['id' => $assetId->toRfc4122()],
            );
        }

        try {
            quotaCreator($db)($userId, null, $softDeleted ? 'soft-deleted-counts.bin' : 'committed-counts.bin', 101, null);
            throw new RuntimeException('Committed usage did not contribute to quota.');
        } catch (UploadQuotaExceeded $error) {
            requireQuotaCondition($error->committedBytes === 900, 'Committed usage calculation was incorrect.');
        }
    }

    $db->delete('media_assets', ['id' => $assetId->toRfc4122()]);
    $assetId = null;
    $afterPurge = quotaCreator($db)($userId, null, 'after-purge.bin', 101, null);
    $db->delete('upload_sessions', ['id' => $afterPurge->id->toRfc4122()]);

    // Expired-session cleanup releases the reservation exactly once via FK cascade.
    $db->delete('user_storage_quotas', ['user_id' => $userId->toRfc4122()]);
    $expiredId = Uuid::v7();
    $createdAt = new DateTimeImmutable('-2 days');
    $expired = UploadSession::reconstitute(
        $expiredId,
        $userId,
        null,
        'expired.bin',
        50,
        null,
        'temporary/'.$expiredId->toRfc4122().'/source',
        UploadStatus::Created,
        new DateTimeImmutable('-1 day'),
        $createdAt,
        $createdAt,
    );
    $sessions = new DbalUploadSessionRepository($db);
    (new DbalUploadQuota($db, 0))->reserve(
        $expiredId,
        $userId,
        50,
        static function () use ($sessions, $expired): void {
            $sessions->save($expired);
        },
    );

    $expiredRepository = new DbalExpiredUploadSessionRepository($db);
    $match = null;
    foreach ($expiredRepository->findExpired(new DateTimeImmutable(), 100) as $candidate) {
        if ($candidate->id->equals($expiredId)) {
            $match = $candidate;
            break;
        }
    }
    requireQuotaCondition($match instanceof UploadSession, 'Expired quota fixture was not discovered.');
    $expiredRepository->delete($match);
    requireQuotaCondition(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM upload_quota_reservations WHERE upload_session_id = :id',
            ['id' => $expiredId->toRfc4122()],
        ) === 0,
        'Expired-session deletion did not cascade reservation.',
    );

    echo "OK concurrent reservations serialize on user row\n";
    echo "OK user override and multi-group quota semantics\n";
    echo "OK default unlimited policy still accounts reservations\n";
    echo "OK failed session persistence rolls reservation back\n";
    echo "OK committed and soft-deleted originals count correctly\n";
    echo "OK physical purge releases committed usage\n";
    echo "OK expired cleanup cascades reservation exactly once\n";
} finally {
    if ($db->isTransactionActive()) {
        $db->rollBack();
    }

    $db->executeStatement('DELETE FROM upload_sessions WHERE user_id = :user_id', ['user_id' => $userId->toRfc4122()]);
    $db->executeStatement('DELETE FROM upload_quota_reservations WHERE user_id = :user_id', ['user_id' => $userId->toRfc4122()]);
    $db->delete('user_storage_quotas', ['user_id' => $userId->toRfc4122()]);
    $db->executeStatement(
        'DELETE FROM group_storage_quotas WHERE group_id IN (:one, :two)',
        ['one' => $groupOne->toRfc4122(), 'two' => $groupTwo->toRfc4122()],
    );

    if ($assetId instanceof Uuid) {
        $db->delete('media_assets', ['id' => $assetId->toRfc4122()]);
    }

    $db->delete('users', ['id' => $userId->toRfc4122()]);
    $db->executeStatement(
        'DELETE FROM groups WHERE id IN (:one, :two)',
        ['one' => $groupOne->toRfc4122(), 'two' => $groupTwo->toRfc4122()],
    );
    $db->close();
    removeQuotaTree($barrierDirectory);
}
