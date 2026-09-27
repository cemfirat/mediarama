<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Application\LibraryMediaSearchCriteria;
use Mediarama\Media\Infrastructure\Persistence\DbalLibraryMediaSearch;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);

$db = DriverManager::getConnection(
    $dsn->parse((string) getenv('DATABASE_URL')),
);

$viewer = Uuid::v7();
$groupViewer = Uuid::v7();
$owner = Uuid::v7();
$unrelated = Uuid::v7();
$group = Uuid::v7();

/** @var list<Uuid> $mediaIds */
$mediaIds = [];
/** @var list<Uuid> $collectionIds */
$collectionIds = [];

function requireSearch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function insertSearchUser(Connection $db, Uuid $id, string $label): void
{
    $now = (new DateTimeImmutable())->format(DATE_ATOM);

    $db->insert('users', [
        'id' => $id->toRfc4122(),
        'username' => 'library-search-'.$label.'-'.$id->toRfc4122(),
        'email' => null,
        'password_hash' => null,
        'display_name' => $label,
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

function grantSearchAccess(
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

function insertSearchMedia(
    Connection $db,
    Uuid $id,
    Uuid $ownerId,
    string $title,
    string $moderationState = 'published',
    string $processingState = 'ready',
    ?string $creator = null,
    ?string $cameraMake = null,
    ?string $cameraModel = null,
    ?string $lens = null,
    ?int $iso = null,
    ?string $capturedAt = null,
    ?float $latitude = null,
    ?float $longitude = null,
    ?string $locationName = null,
    ?string $deletedAt = null,
): void {
    $now = (new DateTimeImmutable())->format(DATE_ATOM);
    $idString = $id->toRfc4122();

    $db->insert('media_assets', [
        'id' => $idString,
        'owner_id' => $ownerId->toRfc4122(),
        'storage_disk' => 'local',
        'storage_key' => 'library-search/'.$idString.'/source',
        'original_filename' => strtolower(str_replace(' ', '-', $title)).'.jpg',
        'mime_type' => 'image/jpeg',
        'media_type' => 'image',
        'byte_size' => 4,
        'checksum_sha256' => hash('sha256', $idString),
        'width' => 64,
        'height' => 48,
        'duration_ms' => null,
        'title' => $title,
        'description' => 'Description for '.$title,
        'captured_at' => $capturedAt,
        'processing_state' => $processingState,
        'moderation_state' => $moderationState,
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => $deletedAt,
        'metadata_provenance' => '{}',
        'creator' => $creator,
        'copyright' => null,
        'camera_make' => $cameraMake,
        'camera_model' => $cameraModel,
        'lens' => $lens,
        'iso' => $iso,
        'aperture' => null,
        'exposure_time' => null,
        'focal_length' => null,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'location_name' => $locationName,
    ]);
}

function addSearchMembership(Connection $db, Uuid $collectionId, Uuid $mediaId): void
{
    $db->insert('collection_media', [
        'collection_id' => $collectionId->toRfc4122(),
        'media_id' => $mediaId->toRfc4122(),
        'position' => 0,
        'added_by' => null,
        'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
}

/** @param list<object> $results
 *  @return list<string>
 */
function resultIds(array $results): array
{
    $ids = array_map(static fn (object $result): string => $result->id->toRfc4122(), $results);
    sort($ids);

    return $ids;
}

/** @param list<Uuid> $expected */
function requireResultIds(array $actual, array $expected, string $message): void
{
    $expectedIds = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $expected);
    sort($expectedIds);

    requireSearch($actual === $expectedIds, $message);
}

try {
    insertSearchUser($db, $viewer, 'viewer');
    insertSearchUser($db, $groupViewer, 'group-viewer');
    insertSearchUser($db, $owner, 'owner');
    insertSearchUser($db, $unrelated, 'unrelated');

    $db->insert(
        'groups',
        [
            'id' => $group->toRfc4122(),
            'slug' => 'library-search-'.$group->toRfc4122(),
            'name' => 'Library Search Group',
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
    $restrictedHidden = Uuid::v7();

    $collectionIds = [
        $public,
        $authenticated,
        $private,
        $restrictedUser,
        $restrictedGroup,
        $restrictedHidden,
    ];

    insertSearchCollection($db, $public, $owner, 'Library Search Public', 'public');
    insertSearchCollection($db, $authenticated, $owner, 'Library Search Authenticated', 'authenticated');
    insertSearchCollection($db, $private, $owner, 'Library Search Private', 'private');
    insertSearchCollection($db, $restrictedUser, $owner, 'Library Search Restricted User', 'restricted');
    insertSearchCollection($db, $restrictedGroup, $owner, 'Library Search Restricted Group', 'restricted');
    insertSearchCollection($db, $restrictedHidden, $owner, 'Library Search Restricted Hidden', 'restricted');

    grantSearchAccess($db, $restrictedUser, 'collection.view', userId: $viewer);
    grantSearchAccess($db, $restrictedGroup, 'collection.view', groupId: $group);

    $ownedUncollected = Uuid::v7();
    $publicPublished = Uuid::v7();
    $publicPending = Uuid::v7();
    $publicRejected = Uuid::v7();
    $authenticatedPending = Uuid::v7();
    $privateHidden = Uuid::v7();
    $restrictedUserMedia = Uuid::v7();
    $restrictedGroupMedia = Uuid::v7();
    $restrictedHiddenMedia = Uuid::v7();
    $mixedMembership = Uuid::v7();
    $notReady = Uuid::v7();
    $deletedOwned = Uuid::v7();

    $mediaIds = [
        $ownedUncollected,
        $publicPublished,
        $publicPending,
        $publicRejected,
        $authenticatedPending,
        $privateHidden,
        $restrictedUserMedia,
        $restrictedGroupMedia,
        $restrictedHiddenMedia,
        $mixedMembership,
        $notReady,
        $deletedOwned,
    ];

    insertSearchMedia(
        $db,
        $ownedUncollected,
        $viewer,
        'Viewer Owned Draft',
        moderationState: 'draft',
        creator: 'Viewer Creator',
        cameraMake: 'Viewer Camera',
        iso: 100,
        capturedAt: '2026-01-01T10:00:00+00:00',
    );
    insertSearchMedia(
        $db,
        $publicPublished,
        $owner,
        'Public Published',
        creator: 'Public Creator',
        cameraMake: 'Public Camera',
        iso: 200,
        capturedAt: '2026-02-01T10:00:00+00:00',
        latitude: 48.2082,
        longitude: 16.3738,
        locationName: 'Vienna Public',
    );
    insertSearchMedia(
        $db,
        $publicPending,
        $owner,
        'Public Pending',
        moderationState: 'pending_review',
        creator: 'Pending Public Creator',
        cameraMake: 'Pending Public Camera',
        iso: 250,
    );
    insertSearchMedia(
        $db,
        $publicRejected,
        $owner,
        'Public Rejected',
        moderationState: 'rejected',
        creator: 'Rejected Public Creator',
        cameraMake: 'Rejected Public Camera',
        iso: 260,
    );
    insertSearchMedia(
        $db,
        $authenticatedPending,
        $owner,
        'Authenticated Pending',
        moderationState: 'pending_review',
        creator: 'Authenticated Creator',
        cameraMake: 'Authenticated Camera',
        iso: 300,
    );
    insertSearchMedia(
        $db,
        $privateHidden,
        $owner,
        'Hidden Private Secret',
        creator: 'Hidden Creator',
        cameraMake: 'SecretCam',
        cameraModel: 'Hidden Model',
        lens: 'Hidden Lens',
        iso: 400,
        latitude: 47.0,
        longitude: 15.0,
        locationName: 'Hidden Exact Place',
    );
    insertSearchMedia(
        $db,
        $restrictedUserMedia,
        $owner,
        'Restricted User Pending',
        moderationState: 'pending_review',
        creator: 'Restricted User Creator',
        cameraMake: 'SharedCam',
        cameraModel: 'Shared Model',
        lens: 'Shared Lens',
        iso: 400,
        capturedAt: '2026-03-01T10:00:00+00:00',
    );
    insertSearchMedia(
        $db,
        $restrictedGroupMedia,
        $owner,
        'Restricted Group',
        creator: 'Restricted Group Creator',
        cameraMake: 'GroupCam',
        iso: 500,
    );
    insertSearchMedia(
        $db,
        $restrictedHiddenMedia,
        $owner,
        'Restricted Hidden Secret',
        creator: 'Hidden Restricted Creator',
        cameraMake: 'SecretCam',
        iso: 450,
    );
    insertSearchMedia(
        $db,
        $mixedMembership,
        $owner,
        'Mixed Membership',
        creator: 'Mixed Creator',
        cameraMake: 'MixedCam',
        iso: 600,
    );
    insertSearchMedia(
        $db,
        $notReady,
        $viewer,
        'Owned Processing',
        moderationState: 'draft',
        processingState: 'processing',
        creator: 'Viewer Creator',
    );
    insertSearchMedia(
        $db,
        $deletedOwned,
        $viewer,
        'Deleted Owned',
        moderationState: 'draft',
        creator: 'Viewer Creator',
        deletedAt: (new DateTimeImmutable())->format(DATE_ATOM),
    );

    addSearchMembership($db, $public, $publicPublished);
    addSearchMembership($db, $public, $publicPending);
    addSearchMembership($db, $public, $publicRejected);
    addSearchMembership($db, $authenticated, $authenticatedPending);
    addSearchMembership($db, $private, $privateHidden);
    addSearchMembership($db, $restrictedUser, $restrictedUserMedia);
    addSearchMembership($db, $restrictedGroup, $restrictedGroupMedia);
    addSearchMembership($db, $restrictedHidden, $restrictedHiddenMedia);
    addSearchMembership($db, $private, $mixedMembership);
    addSearchMembership($db, $restrictedUser, $mixedMembership);

    $search = new DbalLibraryMediaSearch($db);

    requireResultIds(
        resultIds($search->search($viewer, new LibraryMediaSearchCriteria(limit: 200))),
        [$ownedUncollected, $publicPublished, $authenticatedPending, $restrictedUserMedia, $mixedMembership],
        'viewer sees owned, published public, authenticated and explicitly shared media only',
    );

    requireResultIds(
        resultIds($search->search($groupViewer, new LibraryMediaSearchCriteria(limit: 200))),
        [$publicPublished, $authenticatedPending, $restrictedGroupMedia],
        'group viewer sees public, authenticated and group-shared media only',
    );

    requireSearch(
        in_array(
            $publicPending->toRfc4122(),
            resultIds($search->search($owner, new LibraryMediaSearchCriteria(limit: 200))),
            true,
        ),
        'MediaAsset owner can find own pending public media',
    );

    requireResultIds(
        resultIds($search->search(
            $viewer,
            new LibraryMediaSearchCriteria(cameraMake: 'SecretCam', limit: 200),
        )),
        [],
        'camera metadata filter cannot bypass Collection authorization',
    );

    requireResultIds(
        resultIds($search->search(
            $viewer,
            new LibraryMediaSearchCriteria(text: 'Hidden Private Secret', limit: 200),
        )),
        [],
        'full-text search cannot bypass Collection authorization',
    );

    requireResultIds(
        resultIds($search->search(
            $viewer,
            new LibraryMediaSearchCriteria(creator: 'Restricted User Creator', limit: 200),
        )),
        [$restrictedUserMedia],
        'creator filter returns only authorized matching media',
    );

    requireResultIds(
        resultIds($search->search(
            $viewer,
            new LibraryMediaSearchCriteria(minimumIso: 350, maximumIso: 450, limit: 200),
        )),
        [$restrictedUserMedia],
        'ISO range filter excludes inaccessible matching media',
    );

    $withLocation = $search->search(
        $viewer,
        new LibraryMediaSearchCriteria(hasLocation: true, limit: 200),
    );
    requireResultIds(
        resultIds($withLocation),
        [$publicPublished],
        'location-presence filter excludes inaccessible GPS media',
    );
    requireSearch(
        !property_exists($withLocation[0], 'latitude')
        && !property_exists($withLocation[0], 'longitude')
        && !property_exists($withLocation[0], 'metadata')
        && !property_exists($withLocation[0], 'storageKey'),
        'library search result omits exact GPS, raw metadata and storage paths',
    );

    requireResultIds(
        resultIds($search->search(
            $viewer,
            new LibraryMediaSearchCriteria(
                capturedFrom: new DateTimeImmutable('2026-02-15T00:00:00+00:00'),
                capturedUntil: new DateTimeImmutable('2026-03-15T23:59:59+00:00'),
                limit: 200,
            ),
        )),
        [$restrictedUserMedia],
        'capture-date filter remains authorization-aware',
    );

    requireResultIds(
        resultIds($search->search(
            $unrelated,
            new LibraryMediaSearchCriteria(limit: 200),
        )),
        [$publicPublished, $authenticatedPending],
        'unrelated authenticated user sees no private or restricted media',
    );

    echo "Actor-aware library media search integration checks passed.".PHP_EOL;
} finally {
    foreach (array_reverse($mediaIds) as $mediaId) {
        $db->executeStatement('DELETE FROM media_assets WHERE id = :id', [
            'id' => $mediaId->toRfc4122(),
        ]);
    }

    foreach (array_reverse($collectionIds) as $collectionId) {
        $db->executeStatement('DELETE FROM collections WHERE id = :id', [
            'id' => $collectionId->toRfc4122(),
        ]);
    }

    $db->executeStatement('DELETE FROM groups WHERE id = :id', [
        'id' => $group->toRfc4122(),
    ]);

    foreach ([$viewer, $groupViewer, $owner, $unrelated] as $userId) {
        $db->executeStatement('DELETE FROM users WHERE id = :id', [
            'id' => $userId->toRfc4122(),
        ]);
    }

    $db->close();
}
