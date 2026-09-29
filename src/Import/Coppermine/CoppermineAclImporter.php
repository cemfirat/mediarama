<?php

declare(strict_types=1);

namespace Mediarama\Import\Coppermine;

use Doctrine\DBAL\Connection;
use Mediarama\Import\Application\ImportMappingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class CoppermineAclImporter
{
    private const FIRST_USER_CAT = 10000;

    public function __construct(
        private CoppermineConnectionFactory $sourceFactory,
        private Connection $target,
        private ImportMappingRepository $mappings,
        private CoppermineSourceKey $sourceKey,
        private CoppermineTablePrefix $prefix,
    ) {
    }

    public function import(): CoppermineAclImportReport
    {
        $source = $this->sourceFactory->create();
        $table = $source->quoteIdentifier($this->prefix->table('albums'));

        $rows = $source->fetchAllAssociative(
            'SELECT aid, owner, visibility, uploads, comments, votes, alb_password, alb_password_hint FROM '.$table.' ORDER BY aid ASC',
        );

        $capabilityGroups = $this->capabilityGroups($source);

        $rules = 0;
        $unmapped = [];
        $passwordReset = [];

        foreach ($rows as $row) {
            $aid = (string) $row['aid'];
            $collectionId = $this->mappings->findTargetId($this->sourceKey->value(), 'album', $aid);
            if ($collectionId === null) {
                $unmapped[] = 'album:'.$aid;
                continue;
            }

            $passwordProtected = trim((string) $row['alb_password']) !== '';
            if ($passwordProtected) {
                // Coppermine stores album passwords as MD5. Never reuse that hash.
                $this->target->executeStatement(
                    <<<'SQL'
UPDATE collections
SET visibility = 'restricted',
    password_protected = TRUE,
    password_hash = NULL,
    password_hint = :hint,
    password_reset_required = TRUE,
    updated_at = :updated_at
WHERE id = :id
SQL,
                    [
                        'id' => $collectionId->toRfc4122(),
                        'hint' => trim((string) $row['alb_password_hint']) !== ''
                            ? (string) $row['alb_password_hint']
                            : null,
                        'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ],
                );
                $passwordReset[] = $aid;
            }

            $ownerId = $this->mappings->findTargetId($this->sourceKey->value(), 'user', (string) $row['owner']);
            if ($ownerId !== null) {
                $rules += $this->rememberRule($collectionId, $ownerId, null, 'collection.view');
            }

            $rules += $this->importAlbumCapabilities(
                $collectionId,
                $aid,
                $row,
                $capabilityGroups,
                $unmapped,
            );

            $visibility = (int) $row['visibility'];
            if ($visibility === 0) {
                continue;
            }

            $this->target->update(
                'collections',
                [
                    'visibility' => 'restricted',
                    'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                ['id' => $collectionId->toRfc4122()],
            );

            if ($visibility >= self::FIRST_USER_CAT) {
                $sourceUserId = (string) ($visibility - self::FIRST_USER_CAT);
                $userId = $this->mappings->findTargetId($this->sourceKey->value(), 'user', $sourceUserId);

                if ($userId === null) {
                    $unmapped[] = sprintf('album:%s user:%s', $aid, $sourceUserId);
                    continue;
                }

                $rules += $this->rememberRule($collectionId, $userId, null, 'collection.view');
                continue;
            }

            $groupId = $this->mappings->findTargetId($this->sourceKey->value(), 'group', (string) $visibility);
            if ($groupId === null) {
                $unmapped[] = sprintf('album:%s group:%d', $aid, $visibility);
                continue;
            }

            $rules += $this->rememberRule($collectionId, null, $groupId, 'collection.view');
        }

        $categoryCreationRules = $this->importCategoryCreationRules($source, $unmapped);

        return new CoppermineAclImportReport(
            count($rows),
            $rules,
            $categoryCreationRules,
            array_values(array_unique($unmapped)),
            $passwordReset,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function capabilityGroups(Connection $source): array
    {
        $table = $source->quoteIdentifier($this->prefix->table('usergroups'));
        $rows = $source->fetchAllAssociative(
            'SELECT group_id, can_upload_pictures, can_post_comments, can_rate_pictures FROM '.$table.' ORDER BY group_id ASC',
        );

        $groups = [
            'collection.media.add' => [],
            'media.comment' => [],
            'media.rate' => [],
        ];

        foreach ($rows as $row) {
            $sourceGroupId = (string) $row['group_id'];

            if ((int) $row['can_upload_pictures'] === 1) {
                $groups['collection.media.add'][] = $sourceGroupId;
            }
            if ((int) $row['can_post_comments'] === 1) {
                $groups['media.comment'][] = $sourceGroupId;
            }
            if ((int) $row['can_rate_pictures'] === 1) {
                $groups['media.rate'][] = $sourceGroupId;
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $album
     * @param array<string, list<string>> $capabilityGroups
     * @param list<string> $unmapped
     */
    private function importAlbumCapabilities(
        Uuid $collectionId,
        string $sourceAlbumId,
        array $album,
        array $capabilityGroups,
        array &$unmapped,
    ): int {
        $sourceFlags = [
            'collection.media.add' => (string) $album['uploads'],
            'media.comment' => (string) $album['comments'],
            'media.rate' => (string) $album['votes'],
        ];

        $rules = 0;

        foreach ($sourceFlags as $capability => $enabled) {
            if ($enabled !== 'YES') {
                continue;
            }

            foreach ($capabilityGroups[$capability] as $sourceGroupId) {
                $groupId = $this->mappings->findTargetId(
                    $this->sourceKey->value(),
                    'group',
                    $sourceGroupId,
                );

                if ($groupId === null) {
                    $unmapped[] = sprintf(
                        'album:%s capability:%s group:%s',
                        $sourceAlbumId,
                        $capability,
                        $sourceGroupId,
                    );
                    continue;
                }

                $rules += $this->rememberRule(
                    $collectionId,
                    null,
                    $groupId,
                    $capability,
                );
            }
        }

        return $rules;
    }

    /** @param list<string> $unmapped */
    private function importCategoryCreationRules(Connection $source, array &$unmapped): int
    {
        $tableName = $this->prefix->table('categorymap');

        if (!in_array($tableName, $source->createSchemaManager()->listTableNames(), true)) {
            return 0;
        }

        $table = $source->quoteIdentifier($tableName);
        $rows = $source->fetchAllAssociative(
            'SELECT cid, group_id FROM '.$table.' ORDER BY cid ASC, group_id ASC',
        );

        $rules = 0;

        foreach ($rows as $row) {
            $sourceCategoryId = (string) $row['cid'];
            $sourceGroupId = (string) $row['group_id'];

            $collectionId = $this->mappings->findTargetId($this->sourceKey->value(), 'category', $sourceCategoryId);
            $groupId = $this->mappings->findTargetId($this->sourceKey->value(), 'group', $sourceGroupId);

            if ($collectionId === null || $groupId === null) {
                $unmapped[] = sprintf(
                    'categorymap category:%s group:%s',
                    $sourceCategoryId,
                    $sourceGroupId,
                );
                continue;
            }

            $rules += $this->rememberRule(
                $collectionId,
                null,
                $groupId,
                'collection.create_child',
            );
        }

        return $rules;
    }

    private function rememberRule(Uuid $collectionId, ?Uuid $userId, ?Uuid $groupId, string $capability): int
    {
        return $this->target->executeStatement(
            <<<'SQL'
INSERT INTO collection_access (
    id, collection_id, user_id, group_id, capability, effect, created_at
) VALUES (
    :id, :collection_id, :user_id, :group_id, :capability, 'allow', NOW()
)
ON CONFLICT DO NOTHING
SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'collection_id' => $collectionId->toRfc4122(),
                'user_id' => $userId?->toRfc4122(),
                'group_id' => $groupId?->toRfc4122(),
                'capability' => $capability,
            ],
        );
    }
}
