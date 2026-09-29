<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\InspectImageFileGeometry;
use Mediarama\Upload\Application\UploadContentPolicy;

final readonly class CoppermineMigrationPreflight
{
    private const DETAIL_LIMIT = 20;
    private const MIME_PREFIX_BYTES = 262144;

    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private CoppermineTablePrefix $prefix,
        private CoppermineFileLocator $files,
        private UploadContentPolicy $contentPolicy,
        private InspectImageFileGeometry $imageGeometry,
    ) {
    }

    public function inspect(): CoppermineMigrationPreflightReport
    {
        $source = $this->sourceFactory->create();
        $blockers = array_merge(
            $this->configurationAndLanguageBlockers($source),
            $this->duplicateEmailBlockers($source),
            $this->identityGroupPolicyBlockers($source),
            $this->bridgeBlockers($source),
            $this->banBlockers($source),
            $this->unknownPrefixedTableBlockers($source),
            $this->pluginBlockers($source),
            $this->privateAlbumConfigurationBlockers($source),
            $this->moderatorGroupBlockers($source),
            $this->categoryHierarchyBlockers($source),
            $this->coverReferenceBlockers($source),
            $this->unmodeledSourceDataBlockers($source),
            $this->legacyCounterBlockers($source),
            $this->sourceMediaBlockers($source),
        );

        return new CoppermineMigrationPreflightReport($blockers);
    }

    /** @return list<string> */
    private function configurationAndLanguageBlockers(Connection $source): array
    {
        $configTable = $source->quoteIdentifier($this->prefix->table('config'));
        $rows = $source->fetchAllAssociative(
            "SELECT name, value FROM ".$configTable." WHERE name IN ('keyword_separator', 'old_style_rating', 'rating_stars_amount', 'lang')",
        );

        $config = [];
        foreach ($rows as $row) {
            $config[(string) $row['name']] = (string) $row['value'];
        }

        $blockers = [];
        foreach (['keyword_separator', 'old_style_rating', 'rating_stars_amount', 'lang'] as $required) {
            if (!array_key_exists($required, $config)) {
                $blockers[] = sprintf(
                    'Required Coppermine config key "%s" is missing; migration interpretation would be ambiguous.',
                    $required,
                );
            }
        }

        if ($blockers !== []) {
            return array_slice($blockers, 0, self::DETAIL_LIMIT);
        }

        if ($config['keyword_separator'] === '') {
            $blockers[] = 'Coppermine keyword_separator is empty; picture keywords cannot be split safely.';
        }

        if (!in_array($config['old_style_rating'], ['0', '1'], true)) {
            $blockers[] = sprintf(
                'Coppermine old_style_rating has unsupported value "%s"; expected 0 or 1.',
                $config['old_style_rating'],
            );
        }

        if (!ctype_digit($config['rating_stars_amount'])) {
            $blockers[] = sprintf(
                'Coppermine rating_stars_amount has unsupported value "%s"; expected an integer from 1 to 20.',
                $config['rating_stars_amount'],
            );
        } else {
            $stars = (int) $config['rating_stars_amount'];
            if ($stars < 1 || $stars > 20) {
                $blockers[] = sprintf(
                    'Coppermine rating_stars_amount=%d is outside the supported source range 1..20.',
                    $stars,
                );
            }
        }

        $languagesTable = $source->quoteIdentifier($this->prefix->table('languages'));
        $languageRows = $source->fetchAllAssociative(
            'SELECT lang_id, abbr, available FROM '.$languagesTable.' ORDER BY lang_id ASC',
        );

        /** @var array<string, array{abbr:string,available:string}> $languages */
        $languages = [];
        foreach ($languageRows as $row) {
            $languages[(string) $row['lang_id']] = [
                'abbr' => trim((string) $row['abbr']),
                'available' => strtoupper(trim((string) $row['available'])),
            ];
        }

        $defaultLanguage = trim($config['lang']);
        if ($defaultLanguage === '' || !isset($languages[$defaultLanguage])) {
            $blockers[] = sprintf(
                'Coppermine default language "%s" does not resolve through the languages table.',
                $defaultLanguage,
            );
        } elseif ($languages[$defaultLanguage]['abbr'] === '' || $languages[$defaultLanguage]['available'] !== 'YES') {
            $blockers[] = sprintf(
                'Coppermine default language "%s" has no available non-empty locale abbreviation.',
                $defaultLanguage,
            );
        }

        $usersTable = $source->quoteIdentifier($this->prefix->table('users'));
        $userRows = $source->fetchAllAssociative(sprintf(
            "SELECT user_id, user_language FROM %s WHERE TRIM(user_language) <> '' ORDER BY user_id ASC LIMIT %d",
            $usersTable,
            self::DETAIL_LIMIT,
        ));

        foreach ($userRows as $row) {
            $language = trim((string) $row['user_language']);
            if (!isset($languages[$language])) {
                $blockers[] = sprintf(
                    'User %s language "%s" does not resolve through the Coppermine languages table.',
                    (string) $row['user_id'],
                    $language,
                );
            } elseif ($languages[$language]['abbr'] === '' || $languages[$language]['available'] !== 'YES') {
                $blockers[] = sprintf(
                    'User %s language "%s" has no available non-empty locale abbreviation.',
                    (string) $row['user_id'],
                    $language,
                );
            }

            if (count($blockers) >= self::DETAIL_LIMIT) {
                return array_slice($blockers, 0, self::DETAIL_LIMIT);
            }
        }

        return array_slice($blockers, 0, self::DETAIL_LIMIT);
    }

    /** @return list<string> */
    private function duplicateEmailBlockers(Connection $source): array
    {
        $table = $source->quoteIdentifier($this->prefix->table('users'));
        $rows = $source->fetchAllAssociative(sprintf(
            <<<'SQL'
SELECT LOWER(TRIM(user_email)) AS normalized_email, COUNT(*) AS duplicate_count
FROM %s
WHERE TRIM(user_email) <> ''
GROUP BY LOWER(TRIM(user_email))
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC, normalized_email ASC
LIMIT %d
SQL,
            $table,
            self::DETAIL_LIMIT,
        ));

        $blockers = [];
        foreach ($rows as $row) {
            $blockers[] = sprintf(
                'Duplicate normalized email address "%s" is used by %d source users; Mediarama requires unique non-null email addresses.',
                (string) $row['normalized_email'],
                (int) $row['duplicate_count'],
            );
        }

        return $blockers;
    }

    /** @return list<string> */
    private function identityGroupPolicyBlockers(Connection $source): array
    {
        $groupsTable = $source->quoteIdentifier($this->prefix->table('usergroups'));
        $groupRows = $source->fetchAllAssociative(
            'SELECT group_id, group_quota, can_upload_pictures, can_create_albums, pub_upl_need_approval, priv_upl_need_approval, access_level FROM '.$groupsTable.' ORDER BY group_id ASC',
        );

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        foreach ($groupRows as $row) {
            $groupId = (string) $row['group_id'];
            $quota = (int) $row['group_quota'];
            $publicApproval = (int) $row['pub_upl_need_approval'];
            $privateApproval = (int) $row['priv_upl_need_approval'];
            $accessLevel = (int) $row['access_level'];

            if ($quota < 0) {
                return [sprintf('Coppermine group %s has invalid negative group_quota=%d.', $groupId, $quota)];
            }
            if (!in_array($publicApproval, [0, 1], true) || !in_array($privateApproval, [0, 1], true)) {
                return [sprintf('Coppermine group %s has unsupported upload-approval flags; expected 0 or 1.', $groupId)];
            }
            if ($accessLevel < 0 || $accessLevel > 3) {
                return [sprintf('Coppermine group %s has unsupported access_level=%d; expected 0..3.', $groupId, $accessLevel)];
            }

            $groups[$groupId] = $row;
        }

        $usersTable = $source->quoteIdentifier($this->prefix->table('users'));
        $users = $source->fetchAllAssociative(
            'SELECT user_id, user_group, user_group_list FROM '.$usersTable.' ORDER BY user_id ASC',
        );

        $blockers = [];

        foreach ($users as $user) {
            $userId = (string) $user['user_id'];
            $groupIds = $this->sourceUserGroupIds(
                (string) $user['user_group'],
                (string) $user['user_group_list'],
            );

            $effectiveGroups = [];
            $missing = false;

            foreach ($groupIds as $groupId) {
                if (!isset($groups[$groupId])) {
                    $blockers[] = sprintf(
                        'User %s references missing Coppermine group %s; effective permissions and policies cannot be migrated safely.',
                        $userId,
                        $groupId,
                    );
                    $missing = true;

                    if (count($blockers) >= self::DETAIL_LIMIT) {
                        return $blockers;
                    }

                    continue;
                }

                $effectiveGroups[] = $groups[$groupId];
            }

            if ($missing || $effectiveGroups === []) {
                continue;
            }

            $canUpload = max(array_map(
                static fn (array $group): int => max((int) $group['can_upload_pictures'], (int) $group['can_create_albums']),
                $effectiveGroups,
            )) > 0;
            $publicApproval = min(array_map(static fn (array $group): int => (int) $group['pub_upl_need_approval'], $effectiveGroups));
            $privateApproval = min(array_map(static fn (array $group): int => (int) $group['priv_upl_need_approval'], $effectiveGroups));
            $accessLevel = max(array_map(static fn (array $group): int => (int) $group['access_level'], $effectiveGroups));

            if ($canUpload && $publicApproval === 1) {
                $blockers[] = sprintf(
                    'User %s requires Coppermine approval for public-album uploads; Mediarama has no equivalent migrated upload-approval policy yet.',
                    $userId,
                );
            }

            if ($canUpload && $privateApproval === 1) {
                $blockers[] = sprintf(
                    'User %s requires Coppermine approval for private/user-gallery uploads; Mediarama has no equivalent migrated upload-approval policy yet.',
                    $userId,
                );
            }

            if ($accessLevel < 3) {
                $blockers[] = sprintf(
                    'User %s has effective Coppermine access_level=%d; Mediarama does not yet preserve thumbnail/intermediate/full-size access tiers.',
                    $userId,
                    $accessLevel,
                );
            }

            if (count($blockers) >= self::DETAIL_LIMIT) {
                return array_slice($blockers, 0, self::DETAIL_LIMIT);
            }
        }

        return $blockers;
    }

    /** @return list<string> */
    private function sourceUserGroupIds(string $primary, string $additional): array
    {
        $ids = [trim($primary)];

        foreach (preg_split('/[^0-9]+/', $additional) ?: [] as $value) {
            if ($value !== '') {
                $ids[] = $value;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /** @return list<string> */
    private function bridgeBlockers(Connection $source): array
    {
        $configTable = $source->quoteIdentifier($this->prefix->table('config'));
        $rawValue = $source->fetchOne(
            'SELECT value FROM '.$configTable.' WHERE name = :name',
            ['name' => 'bridge_enable'],
        );

        if ($rawValue === false) {
            return ['Required Coppermine config key "bridge_enable" is missing; identity authority cannot be determined safely.'];
        }

        $value = trim((string) $rawValue);
        if ($value === '0') {
            return [];
        }

        if ($value !== '1') {
            return [sprintf(
                'Coppermine config key "bridge_enable" has unsupported value "%s"; expected 0 or 1.',
                $value,
            )];
        }

        $bridgeName = null;
        $schema = $source->createSchemaManager();
        $bridgeTableName = $this->prefix->table('bridge');

        if (in_array($bridgeTableName, $schema->listTableNames(), true)) {
            $bridgeTable = $source->quoteIdentifier($bridgeTableName);
            $shortName = $source->fetchOne(
                'SELECT value FROM '.$bridgeTable.' WHERE name = :name',
                ['name' => 'short_name'],
            );

            if ($shortName !== false && trim((string) $shortName) !== '') {
                $bridgeName = trim((string) $shortName);
            }
        }

        return [sprintf(
            'Coppermine bridging is enabled%s; the local users table cannot be assumed to be the authoritative identity source.',
            $bridgeName !== null ? sprintf(' for "%s"', $bridgeName) : '',
        )];
    }


    /** @return list<string> */
    private function banBlockers(Connection $source): array
    {
        $tableName = $this->prefix->table('banned');
        if (!in_array($tableName, $source->createSchemaManager()->listTableNames(), true)) {
            return [];
        }

        $table = $source->quoteIdentifier($tableName);
        $total = (int) $source->fetchOne('SELECT COUNT(*) FROM '.$table);
        if ($total === 0) {
            return [];
        }

        $manual = (int) $source->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE brute_force = 0');

        return [sprintf(
            'Coppermine contains %d ban record(s) (%d manual, %d brute-force); Mediarama does not yet migrate ban/expiry semantics, so identity migration is blocked until these records are explicitly resolved.',
            $total,
            $manual,
            max(0, $total - $manual),
        )];
    }

    /** @return list<string> */
    private function unknownPrefixedTableBlockers(Connection $source): array
    {
        $knownTables = [];
        foreach (CoppermineCoreSchema::TABLE_SUFFIXES as $suffix) {
            $knownTables[$this->prefix->table($suffix)] = true;
        }

        $blockers = [];
        foreach ($source->createSchemaManager()->listTableNames() as $table) {
            if (!str_starts_with($table, $this->prefix->value) || isset($knownTables[$table])) {
                continue;
            }

            $blockers[] = sprintf(
                'Unknown Coppermine-prefixed table "%s" is not part of the audited 1.6/1.7 core schema; it may contain plugin/custom data and must be audited and resolved before migration.',
                $table,
            );

            if (count($blockers) >= self::DETAIL_LIMIT) {
                break;
            }
        }

        return $blockers;
    }

    /** @return list<string> */
    private function pluginBlockers(Connection $source): array
    {
        $tableName = $this->prefix->table('plugins');
        if (!in_array($tableName, $source->createSchemaManager()->listTableNames(), true)) {
            return [];
        }

        $table = $source->quoteIdentifier($tableName);
        $rows = $source->fetchAllAssociative(sprintf(
            'SELECT plugin_id, name, path, enabled FROM %s ORDER BY plugin_id ASC LIMIT %d',
            $table,
            self::DETAIL_LIMIT,
        ));

        $blockers = [];
        foreach ($rows as $row) {
            $name = trim((string) $row['name']) !== '' ? (string) $row['name'] : 'unnamed plugin';
            $path = trim((string) $row['path']) !== '' ? (string) $row['path'] : '(empty path)';
            $blockers[] = sprintf(
                'Coppermine plugin "%s" at "%s" is installed (%s); plugin-owned files/tables/configuration must be audited or explicitly waived before core migration.',
                $name,
                $path,
                (int) $row['enabled'] === 1 ? 'enabled' : 'disabled',
            );
        }

        return $blockers;
    }

    /** @return list<string> */
    private function privateAlbumConfigurationBlockers(Connection $source): array
    {
        $configTable = $source->quoteIdentifier($this->prefix->table('config'));
        $rawValue = $source->fetchOne(
            'SELECT value FROM '.$configTable.' WHERE name = :name',
            ['name' => 'allow_private_albums'],
        );

        if ($rawValue === false) {
            return ['Required Coppermine config key "allow_private_albums" is missing; effective album visibility cannot be determined safely.'];
        }

        $value = trim((string) $rawValue);
        if ($value === '1') {
            return [];
        }

        if ($value !== '0') {
            return [sprintf(
                'Coppermine config key "allow_private_albums" has unsupported value "%s"; expected 0 or 1.',
                $value,
            )];
        }

        $albums = $source->quoteIdentifier($this->prefix->table('albums'));
        $rows = $source->fetchAllAssociative(sprintf(
            <<<'SQL'
SELECT aid, visibility, alb_password
FROM %s
WHERE visibility <> 0 OR TRIM(COALESCE(alb_password, '')) <> ''
ORDER BY aid ASC
LIMIT %d
SQL,
            $albums,
            self::DETAIL_LIMIT,
        ));

        $blockers = [];
        foreach ($rows as $row) {
            $details = [];
            if ((int) $row['visibility'] !== 0) {
                $details[] = 'visibility='.(string) $row['visibility'];
            }
            if (trim((string) $row['alb_password']) !== '') {
                $details[] = 'password-protected';
            }

            $blockers[] = sprintf(
                'Album %s retains private access metadata (%s) while allow_private_albums=0 made Coppermine treat private-album enforcement as disabled; an explicit target visibility decision is required.',
                (string) $row['aid'],
                implode(', ', $details),
            );
        }

        return $blockers;
    }

    /** @return list<string> */
    private function moderatorGroupBlockers(Connection $source): array
    {
        $schema = $source->createSchemaManager();
        $albumsTableName = $this->prefix->table('albums');
        $columns = array_map(
            static fn ($column): string => strtolower($column->getName()),
            $schema->listTableColumns($albumsTableName),
        );

        if (!in_array('moderator_group', $columns, true)) {
            return [];
        }

        $table = $source->quoteIdentifier($albumsTableName);
        $rows = $source->fetchAllAssociative(sprintf(
            'SELECT aid, moderator_group FROM %s WHERE moderator_group <> 0 ORDER BY aid ASC LIMIT %d',
            $table,
            self::DETAIL_LIMIT,
        ));

        $blockers = [];
        foreach ($rows as $row) {
            $blockers[] = sprintf(
                'Album %s has legacy moderator_group=%d; this residue requires explicit review before ACL migration.',
                (string) $row['aid'],
                (int) $row['moderator_group'],
            );
        }

        return $blockers;
    }


    /** @return list<string> */
    private function categoryHierarchyBlockers(Connection $source): array
    {
        $table = $source->quoteIdentifier($this->prefix->table('categories'));
        $rows = $source->fetchAllAssociative(
            'SELECT cid, parent FROM '.$table.' ORDER BY cid ASC',
        );

        /** @var array<int, int> $parents */
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) $row['cid']] = (int) $row['parent'];
        }

        $blockers = [];

        foreach ($parents as $cid => $parent) {
            if ($parent <= 0) {
                continue;
            }

            if (!array_key_exists($parent, $parents)) {
                $blockers[] = sprintf(
                    'Category %d references missing parent category %d; hierarchy cannot be migrated safely.',
                    $cid,
                    $parent,
                );

                if (count($blockers) >= self::DETAIL_LIMIT) {
                    return $blockers;
                }
            }
        }

        $reportedCycles = [];

        foreach (array_keys($parents) as $start) {
            $path = [];
            $positions = [];
            $current = $start;

            while (array_key_exists($current, $parents) && $parents[$current] > 0) {
                if (array_key_exists($current, $positions)) {
                    $cycle = array_slice($path, $positions[$current]);
                    $canonical = $cycle;
                    sort($canonical, SORT_NUMERIC);
                    $cycleKey = implode(',', $canonical);

                    if (!isset($reportedCycles[$cycleKey])) {
                        $reportedCycles[$cycleKey] = true;
                        $cycle[] = $current;
                        $blockers[] = sprintf(
                            'Category hierarchy contains a cycle: %s.',
                            implode(' -> ', $cycle),
                        );

                        if (count($blockers) >= self::DETAIL_LIMIT) {
                            return $blockers;
                        }
                    }

                    break;
                }

                $positions[$current] = count($path);
                $path[] = $current;

                $parent = $parents[$current];
                if (!array_key_exists($parent, $parents)) {
                    break;
                }

                $current = $parent;
            }
        }

        return $blockers;
    }

    /** @return list<string> */
    private function coverReferenceBlockers(Connection $source): array
    {
        $pictures = $source->quoteIdentifier($this->prefix->table('pictures'));
        $blockers = [];

        $sources = [
            ['table' => 'categories', 'id' => 'cid', 'label' => 'Category'],
            ['table' => 'albums', 'id' => 'aid', 'label' => 'Album'],
        ];

        foreach ($sources as $spec) {
            $table = $source->quoteIdentifier($this->prefix->table($spec['table']));
            $idColumn = $source->quoteIdentifier($spec['id']);
            $rows = $source->fetchAllAssociative(sprintf(
                'SELECT source.%s AS source_id, source.thumb FROM %s source LEFT JOIN %s picture ON picture.pid = source.thumb WHERE source.thumb > 0 AND picture.pid IS NULL ORDER BY source.%s ASC LIMIT %d',
                $idColumn,
                $table,
                $pictures,
                $idColumn,
                self::DETAIL_LIMIT,
            ));

            foreach ($rows as $row) {
                $blockers[] = sprintf(
                    '%s %s explicit thumbnail references missing picture %s.',
                    $spec['label'],
                    (string) $row['source_id'],
                    (string) $row['thumb'],
                );

                if (count($blockers) >= self::DETAIL_LIMIT) {
                    return $blockers;
                }
            }
        }

        return $blockers;
    }

    /** @return list<string> */
    private function unmodeledSourceDataBlockers(Connection $source): array
    {
        $blockers = [];

        $pictures = $source->quoteIdentifier($this->prefix->table('pictures'));
        $pictureRows = $source->fetchAllAssociative(sprintf(
            <<<'SQL'
SELECT pid, user1, user2, user3, user4, url_prefix, galleryicon
FROM %s
WHERE TRIM(user1) <> ''
   OR TRIM(user2) <> ''
   OR TRIM(user3) <> ''
   OR TRIM(user4) <> ''
   OR url_prefix <> 0
   OR galleryicon <> 0
ORDER BY pid ASC
LIMIT %d
SQL,
            $pictures,
            self::DETAIL_LIMIT,
        ));

        foreach ($pictureRows as $row) {
            $customFields = [];
            foreach (['user1', 'user2', 'user3', 'user4'] as $field) {
                if (trim((string) $row[$field]) !== '') {
                    $customFields[] = $field;
                }
            }

            if ($customFields !== []) {
                $blockers[] = sprintf(
                    'Picture %s has populated Coppermine custom media field(s): %s; no explicit Mediarama field mapping exists yet.',
                    (string) $row['pid'],
                    implode(', ', $customFields),
                );
            }

            if ((int) $row['url_prefix'] !== 0) {
                $blockers[] = sprintf(
                    'Picture %s uses Coppermine url_prefix=%d; the current importer supports only the primary local source root and must not guess a multi-server path.',
                    (string) $row['pid'],
                    (int) $row['url_prefix'],
                );
            }

            if ((int) $row['galleryicon'] !== 0) {
                $blockers[] = sprintf(
                    'Picture %s is marked as a Coppermine user-gallery icon; Mediarama has no equivalent user-gallery representation mapping yet.',
                    (string) $row['pid'],
                );
            }

            if (count($blockers) >= self::DETAIL_LIMIT) {
                return array_slice($blockers, 0, self::DETAIL_LIMIT);
            }
        }

        $users = $source->quoteIdentifier($this->prefix->table('users'));
        $userRows = $source->fetchAllAssociative(sprintf(
            <<<'SQL'
SELECT user_id, user_profile1, user_profile2, user_profile3,
       user_profile4, user_profile5, user_profile6
FROM %s
WHERE TRIM(user_profile1) <> ''
   OR TRIM(user_profile2) <> ''
   OR TRIM(user_profile3) <> ''
   OR TRIM(user_profile4) <> ''
   OR TRIM(user_profile5) <> ''
   OR TRIM(user_profile6) <> ''
ORDER BY user_id ASC
LIMIT %d
SQL,
            $users,
            self::DETAIL_LIMIT,
        ));

        foreach ($userRows as $row) {
            $fields = [];
            foreach (['user_profile1', 'user_profile2', 'user_profile3', 'user_profile4', 'user_profile5', 'user_profile6'] as $field) {
                if (trim((string) $row[$field]) !== '') {
                    $fields[] = $field;
                }
            }

            $blockers[] = sprintf(
                'User %s has populated Coppermine profile field(s): %s; no explicit Mediarama profile-field mapping exists yet.',
                (string) $row['user_id'],
                implode(', ', $fields),
            );

            if (count($blockers) >= self::DETAIL_LIMIT) {
                return array_slice($blockers, 0, self::DETAIL_LIMIT);
            }
        }

        return $blockers;
    }

    /** @return list<string> */
    private function legacyCounterBlockers(Connection $source): array
    {
        $pictures = $source->quoteIdentifier($this->prefix->table('pictures'));
        $albums = $source->quoteIdentifier($this->prefix->table('albums'));
        $blockers = [];

        $pictureRows = $source->fetchAllAssociative(sprintf(
            'SELECT pid, hits FROM %s WHERE hits < 0 ORDER BY pid ASC LIMIT %d',
            $pictures,
            self::DETAIL_LIMIT,
        ));

        foreach ($pictureRows as $row) {
            $blockers[] = sprintf(
                'Picture %s has invalid negative hits=%d; historical view_count cannot be migrated safely.',
                (string) $row['pid'],
                (int) $row['hits'],
            );
        }

        if (count($blockers) >= self::DETAIL_LIMIT) {
            return array_slice($blockers, 0, self::DETAIL_LIMIT);
        }

        $albumRows = $source->fetchAllAssociative(sprintf(
            'SELECT aid, alb_hits FROM %s WHERE alb_hits < 0 ORDER BY aid ASC LIMIT %d',
            $albums,
            self::DETAIL_LIMIT - count($blockers),
        ));

        foreach ($albumRows as $row) {
            $blockers[] = sprintf(
                'Album %s has invalid negative alb_hits=%d; historical view_count cannot be migrated safely.',
                (string) $row['aid'],
                (int) $row['alb_hits'],
            );
        }

        return $blockers;
    }

    /** @return list<string> */
    private function sourceMediaBlockers(Connection $source): array
    {
        $table = $source->quoteIdentifier($this->prefix->table('pictures'));
        $rows = $source->iterateAssociative(
            'SELECT pid, filepath, filename FROM '.$table.' ORDER BY pid ASC',
        );
        $blockers = [];

        foreach ($rows as $row) {
            if (count($blockers) >= self::DETAIL_LIMIT) {
                break;
            }

            $sourceId = (string) $row['pid'];

            try {
                $path = $this->files->locate((string) $row['filepath'], (string) $row['filename']);
            } catch (\Throwable $e) {
                $blockers[] = sprintf('Picture %s source path is unsafe: %s', $sourceId, $e->getMessage());
                continue;
            }

            if (!is_file($path) || !is_readable($path)) {
                $blockers[] = sprintf('Picture %s original file is missing or unreadable.', $sourceId);
                continue;
            }

            try {
                $mimeType = $this->detectMimeType($path);
                $this->contentPolicy->assertMimeAllowed($mimeType);

                if (str_starts_with($mimeType, 'image/')) {
                    ($this->imageGeometry)($path);
                }
            } catch (\Throwable $e) {
                $blockers[] = sprintf('Picture %s source media is not importable: %s', $sourceId, $e->getMessage());
            }
        }

        return $blockers;
    }

    private function detectMimeType(string $path): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open source media for MIME inspection.');
        }

        try {
            $prefix = fread($stream, self::MIME_PREFIX_BYTES);
            if ($prefix === false) {
                throw new \RuntimeException('Unable to read source media for MIME inspection.');
            }
        } finally {
            fclose($stream);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($prefix);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
