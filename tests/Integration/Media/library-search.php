<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Application\MediaSearchCriteria;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaSearch;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection($dsn->parse((string) getenv('DATABASE_URL')));

$owner = Uuid::v7();
$viewer = Uuid::v7();
$groupViewer = Uuid::v7();
$unrelated = Uuid::v7();
$group = Uuid::v7();

/** @var list<Uuid> $collections */
$collections = [];
/** @var list<Uuid> $media */
$media = [];

function requireSearch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function insertSearchUser(Connection $db, Uuid $id, string $username): void
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

function insertSearchCollection(
    Connection $db,
    Uuid $id,
    Uuid $ownerId,
    string $title,
    string $visibility,
    ?Uuid $parentId = null,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert(
        'collections',
        [
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
            'password_protected' => false,
            'password_hash' => null,
            'password_hint' => null,
            'password_reset_required' => false,
        ],
        [
            'password_protected' => ParameterType::BOOLEAN,
            'password_reset_required' => ParameterType::BOOLEAN,
        ],
    );
}

function grantSearchView(
    Connection $db,
    Uuid $collectionId,
    ?Uuid $userId = null,
    ?Uuid $groupId = null,
): void {
    $db->insert('collection_access', [
        'id' => Uuid::v7()->toRfc4122(),
        'collection_id' => $collectionId->toRfc4122(),
        'user_id' => $userId?->toRfc4122(),
        'group_id' => $groupId?->toRfc4122(),
        'capability' => 'collection.view',
        'effect' => 'allow',
        'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
}

function insertSearchMedia(
    Connection $db,
    Uuid $id,
    Uuid $ownerId,
    string $title,
    string $moderation = 'pending_review',
    string $processing = 'ready',
    ?string $creator = null,
    ?string $cameraMake = null,
    bool $hasLocation = false,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $db->insert('media_assets', [
        'id' => $id->toRfc4122(),
        'owner_id' => $ownerId->toRfc4122(),
        'storage_disk' => 'media',
        'storage_key' => 'library-search/'.$id->toRfc4122(),
        'original_filename' => strtolower(str_replace(' ', '-', $title)).'.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 123,
        'checksum_sha256' => str_repeat('a', 64),
        'width' => 100,
        'height' => 100,
        'duration_ms' => null,
        'title' => $title,
        'description' => 'Library search integration fixture',
        'captured_at' => '2026-09-27T08:00:00+00:00',
        'processing_state' => $processing,
        'moderation_state' => $moderation,
        'metadata' => '{}',
        'metadata_provenance' => '{}',
        'creator' => $creator,
        'copyright' => null,
        'camera_make' => $cameraMake,
        'camera_model' => null,
        'lens' => null,
        'iso' => 100,
        'aperture' => null,
        'exposure_time' => null,
        'focal_length' => null,
        'latitude' => $hasLocation ? 48.2082 : null,
        'longitude' => $hasLocation ? 16.3738 : null,
        'location_name' => $hasLocation ? 'Vienna' : null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function attachSearchMedia(Connection $db, Uuid $collectionId, Uuid $mediaId, Uuid $userId): void
{
    $db->insert('collection_media', [
        'collection_id' => $collectionId->toRfc4122(),
        'media_id' => $mediaId->toRfc4122(),
        'position' => 0,
        'added_by' => $userId->toRfc4122(),
        'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
}

/** @param list<\Mediarama\Media\Application\MediaSearchResult> $results */
function searchIds(array $results): array
{
    return array_map(static fn ($result): string => $result->id->toRfc4122(), $results);
}

try {
    insertSearchUser($db, $owner, 'library-search-owner-'.$owner->toRfc4122());
    insertSearchUser($db, $viewer, 'library-search-viewer-'.$viewer->toRfc4122());
    insertSearchUser($db, $groupViewer, 'library-search-group-'.$groupViewer->toRfc4122());
    insertSearchUser($db, $unrelated, 'library-search-unrelated-'.$unrelated->toRfc4122());

    $db->insert(
        'groups',
        [
            'id' => $group->toRfc4122(),
            'slug' => 'library-search-'.$group->toRfc4122(),
            'name' => 'Library Search Integration',
            'is_system' => false,
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ],
        ['is_system' => ParameterType::BOOLEAN],
    );
    $db->insert(
        'user_groups',
        [
            'user_id' => $groupViewer->toRfc4122(),
            'group_id' => $group->toRfc4122(),
            'is_primary' => true,
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ],
        ['is_primary' => ParameterType::BOOLEAN],
    );

    $public = Uuid::v7();
    $authenticated = Uuid::v7();
    $private = Uuid::v7();
    $restrictedUser = Uuid::v7();
    $restrictedGroup = Uuid::v7();
    $privateParent = Uuid::v7();
    $publicUnderPrivate = Uuid::v7();
    $collections = [$public, $authenticated, $private, $restrictedUser, $restrictedGroup, $privateParent, $publicUnderPrivate];

    insertSearchCollection($db, $public, $owner, 'Search public', 'public');
    insertSearchCollection($db, $authenticated, $owner, 'Search authenticated', 'authenticated');
    insertSearchCollection($db, $private, $owner, 'Search private', 'private');
    insertSearchCollection($db, $restrictedUser, $owner, 'Search restricted user', 'restricted');
    insertSearchCollection($db, $restrictedGroup, $owner, 'Search restricted group', 'restricted');
    insertSearchCollection($db, $privateParent, $owner, 'Search private parent', 'private');
    insertSearchCollection($db, $publicUnderPrivate, $owner, 'Search public under private', 'public', $privateParent);

    grantSearchView($db, $restrictedUser, userId: $viewer);
    grantSearchView($db, $restrictedGroup, groupId: $group);

    $ownerLoose = Uuid::v7();
    $publicPublished = Uuid::v7();
    $publicPending = Uuid::v7();
    $authenticatedPending = Uuid::v7();
    $privateHidden = Uuid::v7();
    $restrictedUserMedia = Uuid::v7();
    $restrictedGroupMedia = Uuid::v7();
    $ancestorHidden = Uuid::v7();
    $dualMembership = Uuid::v7();
    $secretHidden = Uuid::v7();
    $notReady = Uuid::v7();
    $locatedOwned = Uuid::v7();
    $media = [
        $ownerLoose, $publicPublished, $publicPending, $authenticatedPending, $privateHidden,
        $restrictedUserMedia, $restrictedGroupMedia, $ancestorHidden, $dualMembership,
        $secretHidden, $notReady, $locatedOwned,
    ];

    insertSearchMedia($db, $ownerLoose, $viewer, 'Owner Loose');
    insertSearchMedia($db, $publicPublished, $owner, 'Public Published', moderation: 'published');
    insertSearchMedia($db, $publicPending, $owner, 'Public Pending');
    insertSearchMedia($db, $authenticatedPending, $owner, 'Authenticated Pending');
    insertSearchMedia($db, $privateHidden, $owner, 'Private Hidden');
    insertSearchMedia($db, $restrictedUserMedia, $owner, 'Restricted User');
    insertSearchMedia($db, $restrictedGroupMedia, $owner, 'Restricted Group');
    insertSearchMedia($db, $ancestorHidden, $owner, 'Ancestor Hidden', moderation: 'published');
    insertSearchMedia($db, $dualMembership, $owner, 'Dual Membership');
    insertSearchMedia($db, $secretHidden, $owner, 'Secret Hidden', moderation: 'published', creator: 'Secret Creator', cameraMake: 'Secret Camera', hasLocation: true);
    insertSearchMedia($db, $notReady, $owner, 'Not Ready', moderation: 'published', processing: 'processing');
    insertSearchMedia($db, $locatedOwned, $viewer, 'Located Owned', creator: 'Visible Creator', cameraMake: 'Visible Camera', hasLocation: true);

    attachSearchMedia($db, $public, $publicPublished, $owner);
    attachSearchMedia($db, $public, $publicPending, $owner);
    attachSearchMedia($db, $authenticated, $authenticatedPending, $owner);
    attachSearchMedia($db, $private, $privateHidden, $owner);
    attachSearchMedia($db, $restrictedUser, $restrictedUserMedia, $owner);
    attachSearchMedia($db, $restrictedGroup, $restrictedGroupMedia, $owner);
    attachSearchMedia($db, $publicUnderPrivate, $ancestorHidden, $owner);
    attachSearchMedia($db, $private, $dualMembership, $owner);
    attachSearchMedia($db, $restrictedUser, $dualMembership, $owner);
    attachSearchMedia($db, $private, $secretHidden, $owner);
    attachSearchMedia($db, $public, $notReady, $owner);

    $search = new DbalMediaSearch($db);

    $viewerResults = $search->search($viewer, new MediaSearchCriteria(limit: 200));
    $viewerIds = searchIds($viewerResults);

    foreach ([$ownerLoose, $publicPublished, $authenticatedPending, $restrictedUserMedia, $dualMembership, $locatedOwned] as $expected) {
        requireSearch(in_array($expected->toRfc4122(), $viewerIds, true), 'viewer can find expected accessible media '.$expected->toRfc4122());
    }
    foreach ([$publicPending, $privateHidden, $restrictedGroupMedia, $ancestorHidden, $secretHidden, $notReady] as $forbidden) {
        requireSearch(!in_array($forbidden->toRfc4122(), $viewerIds, true), 'viewer cannot find inaccessible media '.$forbidden->toRfc4122());
    }

    requireSearch(
        count(array_filter($viewerIds, static fn (string $id): bool => $id === $dualMembership->toRfc4122())) === 1,
        'one accessible membership exposes media exactly once even with another inaccessible membership',
    );

    $groupIds = searchIds($search->search($groupViewer, new MediaSearchCriteria(limit: 200)));
    requireSearch(in_array($restrictedGroupMedia->toRfc4122(), $groupIds, true), 'restricted group grant exposes media');
    requireSearch(!in_array($restrictedUserMedia->toRfc4122(), $groupIds, true), 'unrelated restricted user grant does not expose media');

    $ownerIds = searchIds($search->search($owner, new MediaSearchCriteria(limit: 200)));
    requireSearch(in_array($privateHidden->toRfc4122(), $ownerIds, true), 'media owner can find owned private media');
    requireSearch(in_array($publicPending->toRfc4122(), $ownerIds, true), 'media owner can find own pending public-collection media');

    $unrelatedIds = searchIds($search->search($unrelated, new MediaSearchCriteria(limit: 200)));
    requireSearch(in_array($publicPublished->toRfc4122(), $unrelatedIds, true), 'published public collection media is visible to authenticated actor');
    requireSearch(!in_array($publicPending->toRfc4122(), $unrelatedIds, true), 'pending public collection media is not exposed to unrelated actor');

    requireSearch(
        $search->search($viewer, new MediaSearchCriteria(creator: 'Secret Creator')) === [],
        'creator filter cannot bypass authorization',
    );
    requireSearch(
        $search->search($viewer, new MediaSearchCriteria(cameraMake: 'Secret Camera')) === [],
        'camera filter cannot bypass authorization',
    );
    requireSearch(
        $search->search($viewer, new MediaSearchCriteria(text: 'secret-hidden')) === [],
        'filename/text filter cannot bypass authorization',
    );

    $locationIds = searchIds($search->search($viewer, new MediaSearchCriteria(hasLocation: true, limit: 200)));
    requireSearch(in_array($locatedOwned->toRfc4122(), $locationIds, true), 'location-presence filter keeps accessible owned media');
    requireSearch(!in_array($secretHidden->toRfc4122(), $locationIds, true), 'location-presence filter does not leak inaccessible media');

    $noLocationIds = searchIds($search->search($viewer, new MediaSearchCriteria(hasLocation: false, limit: 200)));
    requireSearch(!in_array($privateHidden->toRfc4122(), $noLocationIds, true), 'location-absence filter does not bypass authorization');

    echo "Authenticated library media search integration checks passed.".PHP_EOL;
} finally {
    foreach (array_reverse($collections) as $collectionId) {
        $db->executeStatement('DELETE FROM collections WHERE id = :id', ['id' => $collectionId->toRfc4122()]);
    }
    foreach (array_reverse($media) as $mediaId) {
        $db->executeStatement('DELETE FROM media_assets WHERE id = :id', ['id' => $mediaId->toRfc4122()]);
    }

    $db->executeStatement('DELETE FROM groups WHERE id = :id', ['id' => $group->toRfc4122()]);
    foreach ([$owner, $viewer, $groupViewer, $unrelated] as $userId) {
        $db->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $userId->toRfc4122()]);
    }

    $db->close();
}
