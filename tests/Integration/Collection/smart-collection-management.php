<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Application\SmartCollectionUnavailableException;
use Mediarama\Collection\Domain\SmartCollectionRule;
use Mediarama\Collection\Infrastructure\Persistence\DbalSmartCollectionManagement;
use Symfony\Component\Uid\Uuid;

function requireSmartManagement(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));
$management = new DbalSmartCollectionManagement($db);

$owner = Uuid::v7();
$other = Uuid::v7();
$collectionId = null;
$now = (new DateTimeImmutable())->format(DATE_ATOM);

try {
    foreach ([
        [$owner, 'smart-management-owner'],
        [$other, 'smart-management-other'],
    ] as [$id, $username]) {
        $db->insert('users', [
            'id' => $id->toRfc4122(),
            'username' => $username,
            'email' => null,
            'password_hash' => null,
            'display_name' => $username,
            'status' => 'active',
            'locale' => 'en',
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => null,
        ]);
    }

    $rule = SmartCollectionRule::fromArray([
        'version' => 1,
        'op' => 'and',
        'rules' => [[
            'field' => 'media_type',
            'operator' => 'eq',
            'value' => 'image',
        ]],
    ]);

    $collectionId = $management->create($owner, 'My Smart Images', null, $rule);

    $row = $db->fetchAssociative(
        'SELECT owner_id, title, visibility, mode, smart_rule, deleted_at
         FROM collections
         WHERE id = :id',
        ['id' => $collectionId->toRfc4122()],
    );

    requireSmartManagement(
        $row !== false
        && (string) $row['owner_id'] === $owner->toRfc4122()
        && (string) $row['title'] === 'My Smart Images'
        && (string) $row['visibility'] === 'private'
        && (string) $row['mode'] === 'smart'
        && $row['deleted_at'] === null,
        'create persists an owned private Smart Collection',
    );

    requireSmartManagement(
        (int) $db->fetchOne(
            'SELECT COUNT(*) FROM collection_media WHERE collection_id = :id',
            ['id' => $collectionId->toRfc4122()],
        ) === 0,
        'management never materializes Smart membership',
    );

    $owned = $management->owned($owner);
    requireSmartManagement(
        count($owned) === 1
        && $owned[0]->id->equals($collectionId)
        && $owned[0]->title === 'My Smart Images',
        'owner listing returns the private Smart Collection',
    );
    requireSmartManagement(
        $management->getOwned($other, $collectionId) === null,
        'non-owner cannot read Smart management data',
    );

    $updatedRule = SmartCollectionRule::fromArray([
        'version' => 1,
        'op' => 'and',
        'rules' => [[
            'field' => 'camera_model',
            'operator' => 'contains',
            'value' => 'Nikon',
        ]],
    ]);
    $management->update(
        $owner,
        $collectionId,
        'Nikon picks',
        'Public-facing description draft',
        $updatedRule,
    );

    $updated = $management->getOwned($owner, $collectionId);
    requireSmartManagement(
        $updated !== null
        && $updated->title === 'Nikon picks'
        && $updated->description === 'Public-facing description draft'
        && $updated->rule->payload()['rules'][0]['field'] === 'camera_model',
        'owner can update title and validated Smart rule',
    );

    try {
        $management->update(
            $other,
            $collectionId,
            'Unauthorized',
            null,
            $updatedRule,
        );
        throw new RuntimeException('Expected non-owner update to fail.');
    } catch (SmartCollectionUnavailableException) {
        echo "OK non-owner Smart mutation fails closed".PHP_EOL;
    }

    $management->delete($owner, $collectionId);
    requireSmartManagement(
        $management->getOwned($owner, $collectionId) === null
        && $db->fetchOne(
            'SELECT deleted_at FROM collections WHERE id = :id',
            ['id' => $collectionId->toRfc4122()],
        ) !== null,
        'delete uses soft-delete lifecycle and removes item from management reads',
    );
} finally {
    if ($collectionId !== null) {
        $db->delete('collections', ['id' => $collectionId->toRfc4122()]);
    }
    $db->delete('users', ['id' => $owner->toRfc4122()]);
    $db->delete('users', ['id' => $other->toRfc4122()]);
    $db->close();
}
