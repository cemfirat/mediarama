<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Collection\Infrastructure\Persistence\DbalCollectionAccessPolicy;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);

$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$owner = Uuid::v7();
$viewer = Uuid::v7();
$groupViewer = Uuid::v7();
$unrelated = Uuid::v7();
$group = Uuid::v7();

/** @var list<Uuid> $collections */
$collections = [];

function requireAccess(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function insertUser(Connection $db, Uuid $id, string $username): void
{
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

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

function insertCollection(
    Connection $db,
    Uuid $id,
    Uuid $ownerId,
    string $title,
    string $visibility,
    ?Uuid $parentId = null,
    bool $passwordProtected = false,
    bool $passwordResetRequired = false,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('collections', [
        'id' => $id->toRfc4122(),
        'owner_id' => $ownerId->toRfc4122(),
        'parent_id' => $parentId?->toRfc4122(),
        'cover_media_id' => null,
        'slug' => null,
        'title' => $title,
        'description' => null,
        'visibility' => $visibility,
        'position' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
        'password_protected' => $passwordProtected,
        'password_hash' => null,
        'password_hint' => null,
        'password_reset_required' => $passwordResetRequired,
    ]);
}

function grant(
    Connection $db,
    Uuid $collectionId,
    string $capability,
    ?Uuid $userId = null,
    ?Uuid $groupId = null,
): void {
    $db->insert('collection_access', [
        'id' => Uuid::v7()->toRfc4122(),
        'collection_id' => $collectionId->toRfc4122(),
        'user_id' => $userId?->toRfc4122(),
        'group_id' => $groupId?->toRfc4122(),
        'capability' => $capability,
        'effect' => 'allow',
        'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
}

try {
    insertUser($db, $owner, 'collection-access-owner-'.$owner->toRfc4122());
    insertUser($db, $viewer, 'collection-access-viewer-'.$viewer->toRfc4122());
    insertUser($db, $groupViewer, 'collection-access-group-'.$groupViewer->toRfc4122());
    insertUser($db, $unrelated, 'collection-access-unrelated-'.$unrelated->toRfc4122());

    $db->insert('groups', [
        'id' => $group->toRfc4122(),
        'slug' => 'collection-access-'.$group->toRfc4122(),
        'name' => 'Collection Access Integration',
        'is_system' => false,
        'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
    $db->insert('user_groups', [
        'user_id' => $groupViewer->toRfc4122(),
        'group_id' => $group->toRfc4122(),
        'is_primary' => true,
        'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);

    $publicRoot = Uuid::v7();
    $publicChild = Uuid::v7();
    $authenticated = Uuid::v7();
    $private = Uuid::v7();
    $restrictedUser = Uuid::v7();
    $restrictedGroup = Uuid::v7();
    $restrictedNone = Uuid::v7();
    $privateParent = Uuid::v7();
    $publicUnderPrivate = Uuid::v7();
    $passwordRestricted = Uuid::v7();
    $deletedPublic = Uuid::v7();
    $cycleA = Uuid::v7();
    $cycleB = Uuid::v7();
    $addTarget = Uuid::v7();

    $collections = [
        $publicRoot, $publicChild, $authenticated, $private, $restrictedUser,
        $restrictedGroup, $restrictedNone, $privateParent, $publicUnderPrivate,
        $passwordRestricted, $deletedPublic, $cycleA, $cycleB, $addTarget,
    ];

    insertCollection($db, $publicRoot, $owner, 'Public root', 'public');
    insertCollection($db, $publicChild, $owner, 'Public child', 'public', $publicRoot);
    insertCollection($db, $authenticated, $owner, 'Authenticated', 'authenticated');
    insertCollection($db, $private, $owner, 'Private', 'private');
    insertCollection($db, $restrictedUser, $owner, 'Restricted user', 'restricted');
    insertCollection($db, $restrictedGroup, $owner, 'Restricted group', 'restricted');
    insertCollection($db, $restrictedNone, $owner, 'Restricted none', 'restricted');
    insertCollection($db, $privateParent, $owner, 'Private parent', 'private');
    insertCollection($db, $publicUnderPrivate, $viewer, 'Public below private', 'public', $privateParent);
    insertCollection(
        $db,
        $passwordRestricted,
        $owner,
        'Password migration',
        'restricted',
        passwordProtected: true,
        passwordResetRequired: true,
    );
    insertCollection($db, $deletedPublic, $owner, 'Deleted public', 'public');
    insertCollection($db, $cycleA, $owner, 'Cycle A', 'public');
    insertCollection($db, $cycleB, $owner, 'Cycle B', 'public', $cycleA);
    insertCollection($db, $addTarget, $owner, 'Add target', 'private');

    $db->update(
        'collections',
        ['deleted_at' => (new DateTimeImmutable())->format(DATE_ATOM)],
        ['id' => $deletedPublic->toRfc4122()],
    );
    $db->update(
        'collections',
        ['parent_id' => $cycleB->toRfc4122()],
        ['id' => $cycleA->toRfc4122()],
    );

    grant($db, $private, 'collection.view', userId: $viewer);
    grant($db, $restrictedUser, 'collection.view', userId: $viewer);
    grant($db, $restrictedGroup, 'collection.view', groupId: $group);
    grant($db, $passwordRestricted, 'collection.view', userId: $viewer);
    grant($db, $addTarget, 'collection.media.add', userId: $viewer);
    grant($db, $addTarget, 'collection.view', groupId: $group);
    grant($db, $restrictedUser, 'collection.view', groupId: $group);

    $policy = new DbalCollectionAccessPolicy($db);

    requireAccess($policy->canView(null, $publicRoot), 'anonymous can view effective public root');
    requireAccess($policy->canView(null, $publicChild), 'anonymous can view effective public child');
    requireAccess(!$policy->canView(null, $authenticated), 'anonymous cannot view authenticated collection');
    requireAccess($policy->canView($viewer, $authenticated), 'authenticated actor can view authenticated collection');

    requireAccess($policy->canView($owner, $private), 'private collection owner can view');
    requireAccess(!$policy->canView($viewer, $private), 'private collection ignores explicit view grant for unrelated actor');

    requireAccess($policy->canView($owner, $restrictedUser), 'restricted collection owner can view');
    requireAccess($policy->canView($viewer, $restrictedUser), 'restricted explicit user grant can view');
    requireAccess($policy->canView($groupViewer, $restrictedGroup), 'restricted explicit group grant can view');
    requireAccess(!$policy->canView($unrelated, $restrictedNone), 'unrelated actor cannot view restricted collection');

    requireAccess(
        !$policy->canView($viewer, $publicUnderPrivate),
        'child cannot widen visibility beyond inaccessible private ancestor',
    );
    requireAccess(
        $policy->canView($owner, $publicUnderPrivate),
        'ancestor owner can traverse into public child',
    );

    requireAccess(
        $policy->canView($owner, $passwordRestricted),
        'owner can manage/view own password-reset collection',
    );
    requireAccess(
        !$policy->canView($viewer, $passwordRestricted),
        'password-reset collection remains fail-closed for granted non-owner',
    );

    requireAccess(!$policy->canView(null, $deletedPublic), 'deleted collection is not anonymously viewable');
    requireAccess(!$policy->canView($owner, $deletedPublic), 'deleted collection is not owner-viewable');
    requireAccess(!$policy->canView(null, $cycleA), 'cyclic hierarchy is not publicly viewable');
    requireAccess(!$policy->canView($owner, $cycleA), 'cyclic hierarchy fails closed for authenticated actor');

    requireAccess($policy->canAddMedia($owner, $addTarget), 'collection owner can add media');
    requireAccess($policy->canAddMedia($viewer, $addTarget), 'explicit media-add user grant can add media');
    requireAccess(
        !$policy->canView($viewer, $addTarget),
        'media-add grant does not imply collection view on private collection',
    );
    requireAccess(
        !$policy->canAddMedia($groupViewer, $addTarget),
        'view-only group grant does not imply media-add capability',
    );
    requireAccess(
        !$policy->canAddMedia($viewer, $restrictedUser),
        'collection.view grant does not imply media-add capability',
    );

    echo "Collection access policy integration checks passed.".PHP_EOL;
} finally {
    foreach (array_reverse($collections) as $collectionId) {
        $db->executeStatement(
            'DELETE FROM collections WHERE id = :id',
            ['id' => $collectionId->toRfc4122()],
        );
    }

    $db->executeStatement('DELETE FROM groups WHERE id = :id', ['id' => $group->toRfc4122()]);

    foreach ([$owner, $viewer, $groupViewer, $unrelated] as $userId) {
        $db->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId->toRfc4122()]);
    }

    $db->close();
}
