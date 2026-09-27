<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mediarama\Import\Application\ImportCheckpointRepository;
use Mediarama\Import\Application\ImportMappingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class CoppermineIdentityImporter
{
    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private Connection $target,
        private ImportMappingRepository $mappings,
        private ImportCheckpointRepository $checkpoints,
        private CoppermineSourceKey $sourceKey,
        private CoppermineTablePrefix $prefix,
    ) {
    }

    public function importGroups(int $batchSize = 100): int
    {
        $source = $this->sourceFactory->create();
        $cursor = (int) ($this->checkpoints->get($this->sourceKey->value(), 'groups') ?? '0');
        $table = $source->quoteIdentifier($this->prefix->table('usergroups'));

        $rows = $source->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE group_id > :cursor ORDER BY group_id ASC LIMIT %d',
                $table,
                max(1, min($batchSize, 500)),
            ),
            ['cursor' => $cursor],
        );

        foreach ($rows as $row) {
            $sourceId = (string) $row['group_id'];
            $targetId = $this->mappings->findTargetId($this->sourceKey->value(), 'group', $sourceId) ?? Uuid::v7();
            $slug = 'coppermine-group-'.$sourceId;

            $this->target->executeStatement(
                <<<'SQL'
INSERT INTO groups (id, slug, name, is_system, created_at, updated_at)
VALUES (:id, :slug, :name, :system, NOW(), NOW())
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    updated_at = NOW()
SQL,
                [
                    'id' => $targetId->toRfc4122(),
                    'slug' => $slug,
                    'name' => (string) $row['group_name'],
                    'system' => ((int) $row['has_admin_access']) === 1,
                ],
                ['system' => ParameterType::BOOLEAN],
            );

            $sourceQuotaKiB = (int) $row['group_quota'];
            if ($sourceQuotaKiB < 0) {
                throw new \RuntimeException('Coppermine group quota must not be negative.');
            }

            $this->target->executeStatement(
                <<<'SQL'
INSERT INTO group_storage_quotas (group_id, limit_bytes, updated_at)
VALUES (:group_id, :limit_bytes, NOW())
ON CONFLICT (group_id) DO UPDATE SET
    limit_bytes = EXCLUDED.limit_bytes,
    updated_at = NOW()
SQL,
                [
                    'group_id' => $targetId->toRfc4122(),
                    // Coppermine stores group_quota in KiB. Zero means unlimited.
                    'limit_bytes' => $sourceQuotaKiB * 1024,
                ],
            );

            foreach ($this->permissionKeys($row) as $permission) {
                $this->target->executeStatement(
                    <<<'SQL'
INSERT INTO group_permissions (group_id, permission_key)
VALUES (:group_id, :permission)
ON CONFLICT DO NOTHING
SQL,
                    ['group_id' => $targetId->toRfc4122(), 'permission' => $permission],
                );
            }

            $this->mappings->remember($this->sourceKey->value(), 'group', $sourceId, $targetId);
            $this->checkpoints->save($this->sourceKey->value(), 'groups', $sourceId);
        }

        return count($rows);
    }

    public function importUsers(int $batchSize = 100): int
    {
        $source = $this->sourceFactory->create();
        $cursor = (int) ($this->checkpoints->get($this->sourceKey->value(), 'users') ?? '0');
        $table = $source->quoteIdentifier($this->prefix->table('users'));
        $languageLocales = $this->languageLocales($source);

        $rows = $source->fetchAllAssociative(
            sprintf(
                'SELECT user_id, user_group, user_group_list, user_active, user_name, user_email, user_language, user_regdate, user_lastvisit
                 FROM %s WHERE user_id > :cursor ORDER BY user_id ASC LIMIT %d',
                $table,
                max(1, min($batchSize, 500)),
            ),
            ['cursor' => $cursor],
        );

        foreach ($rows as $row) {
            $sourceId = (string) $row['user_id'];
            $targetId = $this->mappings->findTargetId($this->sourceKey->value(), 'user', $sourceId) ?? Uuid::v7();

            $this->target->executeStatement(
                <<<'SQL'
INSERT INTO users (
    id, username, email, password_hash, display_name, status, locale,
    created_at, updated_at, last_login_at
) VALUES (
    :id, :username, :email, NULL, :display_name, :status, :locale,
    :created_at, NOW(), :last_login_at
)
ON CONFLICT (id) DO UPDATE SET
    username = EXCLUDED.username,
    email = EXCLUDED.email,
    display_name = EXCLUDED.display_name,
    status = EXCLUDED.status,
    locale = EXCLUDED.locale,
    updated_at = NOW(),
    last_login_at = EXCLUDED.last_login_at
SQL,
                [
                    'id' => $targetId->toRfc4122(),
                    'username' => (string) $row['user_name'],
                    'email' => trim((string) $row['user_email']) !== '' ? (string) $row['user_email'] : null,
                    'display_name' => (string) $row['user_name'],
                    'status' => (string) $row['user_active'] === 'YES' ? 'password_reset_required' : 'inactive',
                    'locale' => $this->localeForLanguage((string) $row['user_language'], $languageLocales),
                    'created_at' => $this->safeDate((string) $row['user_regdate'])->format(DATE_ATOM),
                    'last_login_at' => $this->nullableDate((string) $row['user_lastvisit'])?->format(DATE_ATOM),
                ],
            );

            // Clear the previous primary marker first so a changed source primary group
            // cannot violate Mediarama's one-primary-group-per-user invariant.
            $this->target->executeStatement(
                'UPDATE user_groups SET is_primary = FALSE WHERE user_id = :user_id',
                ['user_id' => $targetId->toRfc4122()],
            );

            $groupIds = $this->groupIds((string) $row['user_group'], (string) $row['user_group_list']);
            foreach ($groupIds as $index => $sourceGroupId) {
                $groupId = $this->mappings->findTargetId($this->sourceKey->value(), 'group', $sourceGroupId);
                if ($groupId === null) {
                    continue;
                }

                $this->target->executeStatement(
                    <<<'SQL'
INSERT INTO user_groups (user_id, group_id, is_primary, created_at)
VALUES (:user_id, :group_id, :primary, NOW())
ON CONFLICT (user_id, group_id) DO UPDATE SET is_primary = EXCLUDED.is_primary
SQL,
                    [
                        'user_id' => $targetId->toRfc4122(),
                        'group_id' => $groupId->toRfc4122(),
                        'primary' => $index === 0,
                    ],
                    ['primary' => ParameterType::BOOLEAN],
                );
            }

            $this->mappings->remember($this->sourceKey->value(), 'user', $sourceId, $targetId);
            $this->checkpoints->save($this->sourceKey->value(), 'users', $sourceId);
        }

        return count($rows);
    }

    /** @param array<string,mixed> $row
     *  @return list<string>
     */
    private function permissionKeys(array $row): array
    {
        $permissions = [];

        if ((int) $row['has_admin_access'] === 1) {
            $permissions[] = 'system.admin';
        }
        if ((int) $row['can_upload_pictures'] === 1) {
            $permissions[] = 'media.upload';
        }
        if ((int) $row['can_rate_pictures'] === 1) {
            $permissions[] = 'media.rate';
        }
        if ((int) $row['can_post_comments'] === 1) {
            $permissions[] = 'media.comment';
        }
        if ((int) $row['can_create_albums'] === 1) {
            $permissions[] = 'collection.create';
        }

        return $permissions;
    }

    /** @return array<string,string> */
    private function languageLocales(Connection $source): array
    {
        $table = $source->quoteIdentifier($this->prefix->table('languages'));
        $rows = $source->fetchAllAssociative(
            'SELECT lang_id, abbr FROM '.$table.' ORDER BY lang_id ASC',
        );

        $locales = [];
        foreach ($rows as $row) {
            $language = trim((string) $row['lang_id']);
            $locale = trim((string) $row['abbr']);

            if ($language !== '' && $locale !== '') {
                $locales[$language] = $locale;
            }
        }

        return $locales;
    }

    /** @param array<string,string> $languageLocales */
    private function localeForLanguage(string $language, array $languageLocales): ?string
    {
        $language = trim($language);
        if ($language === '') {
            return null;
        }

        if (!isset($languageLocales[$language])) {
            throw new \RuntimeException(sprintf(
                'Coppermine language "%s" has no locale mapping in the languages table.',
                $language,
            ));
        }

        return $languageLocales[$language];
    }

    /** @return list<string> */
    private function groupIds(string $primary, string $additional): array
    {
        $ids = [trim($primary)];
        foreach (preg_split('/[^0-9]+/', $additional) ?: [] as $value) {
            if ($value !== '') {
                $ids[] = $value;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    private function safeDate(string $value): \DateTimeImmutable
    {
        return $this->nullableDate($value) ?? new \DateTimeImmutable();
    }

    private function nullableDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '' || str_starts_with($value, '1000-')) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
