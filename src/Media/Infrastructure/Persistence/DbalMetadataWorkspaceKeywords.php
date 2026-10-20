<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MetadataWorkspaceKeywords;
use Mediarama\Media\Domain\CanonicalSourceKeywords;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

#[AsAlias]
final readonly class DbalMetadataWorkspaceKeywords implements MetadataWorkspaceKeywords
{
    private const MAX_KEYWORDS = 200;
    private const MAX_BATCH_MEDIA = 200;
    private const MAX_NAME_LENGTH = 160;

    public function __construct(private Connection $connection)
    {
    }

    public function read(Uuid $ownerId, Uuid $mediaId): array
    {
        $this->ownedMetadata(
            $this->connection,
            $ownerId,
            $mediaId,
            false,
        );

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'source' => (string) $row['source'],
            ],
            $this->rows($this->connection, $mediaId),
        );
    }

    public function update(
        Uuid $ownerId,
        Uuid $mediaId,
        string $action,
        array $values,
    ): void {
        if (!in_array($action, ['replace', 'append', 'remove', 'clear', 'revert'], true)) {
            throw new \InvalidArgumentException(
                'Metadata keyword operation is invalid.',
            );
        }

        if (in_array($action, ['clear', 'revert'], true)) {
            if ($values !== []) {
                throw new \InvalidArgumentException(
                    'Clear and revert keyword operations must not carry values.',
                );
            }
        } else {
            $values = $this->names($values);
            if ($values === []) {
                throw new \InvalidArgumentException(
                    'Keyword operation requires at least one non-blank keyword.',
                );
            }
        }

        $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $mediaId,
            $action,
            $values,
        ): void {
            $metadata = $this->ownedMetadata(
                $connection,
                $ownerId,
                $mediaId,
                true,
            );

            $resolvedValues = $values;
            $targetSource = 'manual';

            if ($action === 'revert') {
                $source = CanonicalSourceKeywords::fromStoredSnapshot($metadata);
                if ($source === null) {
                    throw new \InvalidArgumentException(
                        'No unambiguous source keyword list is available to restore.',
                    );
                }

                $resolvedValues = $this->names($source['values']);
                $targetSource = 'embedded';
            }

            $current = $this->rows($connection, $mediaId);
            $currentByName = $this->byName($current);
            $requestedByName = $this->namesByKey($resolvedValues);

            $changed = match ($action) {
                'append' => array_diff_key($requestedByName, $currentByName) !== [],
                'remove' => array_intersect_key($requestedByName, $currentByName) !== [],
                'clear' => $currentByName !== [],
                'replace' => $this->replacementChanged(
                    $currentByName,
                    $requestedByName,
                    'manual',
                ),
                'revert' => $this->replacementChanged(
                    $currentByName,
                    $requestedByName,
                    'embedded',
                ),
                default => false,
            };

            if (!$changed) {
                throw new \InvalidArgumentException(
                    'Metadata keyword update contains no effective change.',
                );
            }

            if ($action === 'append') {
                foreach (array_diff_key($requestedByName, $currentByName) as $name) {
                    $this->attach($connection, $mediaId, $name, 'manual');
                }
            } elseif ($action === 'remove') {
                $this->remove(
                    $connection,
                    $mediaId,
                    array_keys(array_intersect_key($requestedByName, $currentByName)),
                );
            } else {
                $connection->delete('media_tags', [
                    'media_id' => $mediaId->toRfc4122(),
                ]);

                foreach ($requestedByName as $name) {
                    $this->attach(
                        $connection,
                        $mediaId,
                        $name,
                        $targetSource,
                    );
                }
            }

            $connection->update(
                'media_assets',
                ['updated_at' => (new DateTimeImmutable())->format(DATE_ATOM)],
                ['id' => $mediaId->toRfc4122()],
            );
        });
    }

    public function previewMany(
        Uuid $ownerId,
        array $mediaIds,
        string $action,
        array $values,
    ): array {
        [$ids, $values] = $this->batchInput($mediaIds, $action, $values);
        $assets = $this->ownedAssets(
            $this->connection,
            $ownerId,
            $ids,
            false,
        );
        $current = $this->rowsByMedia($this->connection, $ids);

        $preview = [];
        foreach ($assets as $asset) {
            $id = (string) $asset['id'];
            $changes = $this->keywordResult(
                $current[$id] ?? [],
                $action,
                $values,
            );
            $preview[] = [
                'id' => $id,
                'filename' => (string) $asset['original_filename'],
                'title' => $asset['title'] === null
                    ? null
                    : (string) $asset['title'],
                'current' => $changes['current'],
                'result' => $changes['result'],
                'added' => $changes['added'],
                'removed' => $changes['removed'],
                'changed' => $changes['changed'],
            ];
        }

        return $preview;
    }

    public function updateMany(
        Uuid $ownerId,
        array $mediaIds,
        string $action,
        array $values,
    ): int {
        [$ids, $values] = $this->batchInput($mediaIds, $action, $values);

        return $this->connection->transactional(function (Connection $connection) use (
            $ownerId,
            $ids,
            $action,
            $values,
        ): int {
            $assets = $this->ownedAssets(
                $connection,
                $ownerId,
                $ids,
                true,
            );
            $current = $this->rowsByMedia($connection, $ids);
            $updates = [];

            foreach ($assets as $asset) {
                $id = (string) $asset['id'];
                $changes = $this->keywordResult(
                    $current[$id] ?? [],
                    $action,
                    $values,
                );
                if ($changes['changed']) {
                    $updates[$id] = $changes;
                }
            }

            if ($updates === []) {
                throw new \InvalidArgumentException(
                    'Metadata keyword batch contains no effective changes.',
                );
            }

            foreach ($updates as $id => $changes) {
                if ($action === 'append') {
                    foreach ($changes['added'] as $name) {
                        $this->attach(
                            $connection,
                            Uuid::fromString($id),
                            $name,
                            'manual',
                        );
                    }
                } elseif ($action === 'remove') {
                    $this->remove(
                        $connection,
                        Uuid::fromString($id),
                        array_map(
                            static fn (string $name): string => mb_strtolower($name, 'UTF-8'),
                            $changes['removed'],
                        ),
                    );
                } else {
                    $connection->delete('media_tags', ['media_id' => $id]);
                    foreach ($changes['result'] as $name) {
                        $this->attach(
                            $connection,
                            Uuid::fromString($id),
                            $name,
                            'manual',
                        );
                    }
                }

                $connection->update(
                    'media_assets',
                    ['updated_at' => (new DateTimeImmutable())->format(DATE_ATOM)],
                    ['id' => $id],
                );
            }

            return count($updates);
        });
    }

    /**
     * @param list<Uuid> $mediaIds
     * @param list<string> $values
     * @return array{0:list<Uuid>,1:list<string>}
     */
    private function batchInput(
        array $mediaIds,
        string $action,
        array $values,
    ): array {
        if (!in_array($action, ['keep', 'replace', 'append', 'remove', 'clear'], true)) {
            throw new \InvalidArgumentException(
                'Metadata keyword batch operation is invalid.',
            );
        }

        if (
            $mediaIds === []
            || count($mediaIds) > self::MAX_BATCH_MEDIA
        ) {
            throw new \InvalidArgumentException(
                'Metadata keyword batch requires 1-200 MediaAssets.',
            );
        }

        $ids = [];
        foreach ($mediaIds as $mediaId) {
            if (!$mediaId instanceof Uuid) {
                throw new \InvalidArgumentException(
                    'Metadata keyword batch selection is invalid.',
                );
            }

            $ids[$mediaId->toRfc4122()] = $mediaId;
        }

        if (count($ids) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Metadata keyword batch selection must be unique.',
            );
        }

        if ($action === 'keep' || $action === 'clear') {
            if ($values !== []) {
                throw new \InvalidArgumentException(
                    'Keep and clear keyword operations must not carry values.',
                );
            }

            $values = [];
        } else {
            $values = $this->names($values);
            if ($values === []) {
                throw new \InvalidArgumentException(
                    'Keyword batch operation requires at least one non-blank keyword.',
                );
            }
        }

        return [array_values($ids), $values];
    }

    /**
     * @param list<Uuid> $mediaIds
     * @return list<array{id:string,original_filename:string,title:?string}>
     */
    private function ownedAssets(
        Connection $connection,
        Uuid $ownerId,
        array $mediaIds,
        bool $lock,
    ): array {
        $sql = 'SELECT id, original_filename, title
                FROM media_assets
                WHERE owner_id = :owner
                  AND deleted_at IS NULL
                  AND id IN (:media_ids)
                ORDER BY id';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $assets = $connection->fetchAllAssociative(
            $sql,
            [
                'owner' => $ownerId->toRfc4122(),
                'media_ids' => array_map(
                    static fn (Uuid $id): string => $id->toRfc4122(),
                    $mediaIds,
                ),
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );

        if (count($assets) !== count($mediaIds)) {
            throw new \DomainException(
                'Metadata keyword batches may modify only requester-owned MediaAssets.',
            );
        }

        return $assets;
    }

    /**
     * @param list<Uuid> $mediaIds
     * @return array<string,array<string,array{id:string,name:string,source:string}>>
     */
    private function rowsByMedia(
        Connection $connection,
        array $mediaIds,
    ): array {
        $rows = $connection->fetchAllAssociative(
            'SELECT mt.media_id, t.id, t.name, mt.source
             FROM media_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.media_id IN (:media_ids)
             ORDER BY mt.media_id, LOWER(t.name), t.id',
            [
                'media_ids' => array_map(
                    static fn (Uuid $id): string => $id->toRfc4122(),
                    $mediaIds,
                ),
            ],
            ['media_ids' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $mediaId = (string) $row['media_id'];
            $name = (string) $row['name'];
            $result[$mediaId][mb_strtolower($name, 'UTF-8')] = [
                'id' => (string) $row['id'],
                'name' => $name,
                'source' => (string) $row['source'],
            ];
        }

        return $result;
    }

    /**
     * @param array<string,array{id:string,name:string,source:string}> $current
     * @param list<string> $values
     * @return array{
     *     current:list<string>,
     *     result:list<string>,
     *     added:list<string>,
     *     removed:list<string>,
     *     changed:bool
     * }
     */
    private function keywordResult(
        array $current,
        string $action,
        array $values,
    ): array {
        $requested = $this->namesByKey($values);
        $currentNames = [];
        foreach ($current as $row) {
            $currentNames[] = $row['name'];
        }
        $currentByName = $this->namesByKey($currentNames);

        if ($action === 'keep') {
            $addedByName = [];
            $removedByName = [];
            $resultByName = $currentByName;
            $changed = false;
        } elseif ($action === 'append') {
            $addedByName = array_diff_key($requested, $currentByName);
            $resultByName = $currentByName + $addedByName;
            $removedByName = [];
            $changed = $addedByName !== [];
        } elseif ($action === 'remove') {
            $removedByName = array_intersect_key($currentByName, $requested);
            $resultByName = array_diff_key($currentByName, $requested);
            $addedByName = [];
            $changed = $removedByName !== [];
        } elseif ($action === 'clear') {
            $addedByName = [];
            $removedByName = $currentByName;
            $resultByName = [];
            $changed = $currentByName !== [];
        } else {
            $addedByName = array_diff_key($requested, $currentByName);
            $removedByName = array_diff_key($currentByName, $requested);
            $resultByName = $requested;
            $changed = $this->replacementChanged(
                $current,
                $requested,
                'manual',
            );
        }

        return [
            'current' => array_values($currentByName),
            'result' => array_values($resultByName),
            'added' => array_values($addedByName),
            'removed' => array_values($removedByName),
            'changed' => $changed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownedMetadata(
        Connection $connection,
        Uuid $ownerId,
        Uuid $mediaId,
        bool $lock,
    ): array {
        $sql = 'SELECT metadata
                FROM media_assets
                WHERE id = :media
                  AND owner_id = :owner
                  AND deleted_at IS NULL';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $row = $connection->fetchAssociative($sql, [
            'media' => $mediaId->toRfc4122(),
            'owner' => $ownerId->toRfc4122(),
        ]);

        if ($row === false) {
            throw new \DomainException(
                'Metadata keywords may modify only requester-owned MediaAssets.',
            );
        }

        $metadata = $row['metadata'] ?? [];
        if (is_array($metadata)) {
            return $metadata;
        }

        if (!is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array{id:string,name:string,source:string}> */
    private function rows(Connection $connection, Uuid $mediaId): array
    {
        /** @var list<array{id:string,name:string,source:string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT t.id, t.name, mt.source
             FROM media_tags mt
             JOIN tags t ON t.id = mt.tag_id
             WHERE mt.media_id = :media
             ORDER BY LOWER(t.name) ASC, t.id ASC',
            ['media' => $mediaId->toRfc4122()],
        );

        return $rows;
    }

    /**
     * @param list<array{id:string,name:string,source:string}> $rows
     * @return array<string,array{id:string,name:string,source:string}>
     */
    private function byName(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[mb_strtolower($row['name'], 'UTF-8')] = $row;
        }

        return $result;
    }

    /**
     * @param list<string> $values
     * @return array<string,string>
     */
    private function namesByKey(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $result[mb_strtolower($value, 'UTF-8')] = $value;
        }

        return $result;
    }

    /**
     * @param array<string,array{id:string,name:string,source:string}> $current
     * @param array<string,string> $requested
     */
    private function replacementChanged(
        array $current,
        array $requested,
        string $source,
    ): bool {
        $currentNames = array_keys($current);
        $requestedNames = array_keys($requested);
        sort($currentNames, SORT_STRING);
        sort($requestedNames, SORT_STRING);

        if ($currentNames !== $requestedNames) {
            return true;
        }

        foreach ($current as $row) {
            if ($row['source'] !== $source) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $values @return list<string> */
    private function names(array $values): array
    {
        if (count($values) > self::MAX_KEYWORDS) {
            throw new \InvalidArgumentException(
                'Keyword operation supports at most 200 keywords.',
            );
        }

        $result = [];
        $seen = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    'Keyword value is invalid.',
                );
            }

            $value = trim($value);
            $length = iconv_strlen($value, 'UTF-8');
            if (
                $value === ''
                || $length === false
                || $length > self::MAX_NAME_LENGTH
                || preg_match('/[\\x00-\\x1F\\x7F]/u', $value) === 1
            ) {
                throw new \InvalidArgumentException(
                    'Each keyword must contain 1-160 printable characters.',
                );
            }

            $key = mb_strtolower($value, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }

    /** @param list<string> $lowerNames */
    private function remove(
        Connection $connection,
        Uuid $mediaId,
        array $lowerNames,
    ): void {
        if ($lowerNames === []) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM media_tags mt
             USING tags t
             WHERE mt.tag_id = t.id
               AND mt.media_id = :media
               AND LOWER(t.name) IN (:names)',
            [
                'media' => $mediaId->toRfc4122(),
                'names' => $lowerNames,
            ],
            ['names' => ArrayParameterType::STRING],
        );
    }

    private function attach(
        Connection $connection,
        Uuid $mediaId,
        string $name,
        string $source,
    ): void {
        $tagId = $this->resolveTag($connection, $name);

        $connection->executeStatement(
            'INSERT INTO media_tags (media_id, tag_id, source)
             VALUES (:media, :tag, :source)
             ON CONFLICT (media_id, tag_id) DO NOTHING',
            [
                'media' => $mediaId->toRfc4122(),
                'tag' => $tagId->toRfc4122(),
                'source' => $source,
            ],
        );
    }

    private function resolveTag(Connection $connection, string $name): Uuid
    {
        $existing = $connection->fetchOne(
            'SELECT id
             FROM tags
             WHERE LOWER(name) = LOWER(:name)
             ORDER BY id ASC
             LIMIT 1',
            ['name' => $name],
        );

        if ($existing !== false) {
            return Uuid::fromString((string) $existing);
        }

        $slug = (new AsciiSlugger())
            ->slug($name)
            ->lower()
            ->toString();
        $slug = trim(substr($slug, 0, 160), '-');

        if ($slug === '') {
            throw new \InvalidArgumentException(
                'Keyword cannot produce a stable tag slug.',
            );
        }

        $resolvedSlug = $slug;
        if ($connection->fetchOne(
            'SELECT id FROM tags WHERE slug = :slug',
            ['slug' => $resolvedSlug],
        ) !== false) {
            $suffix = substr(
                hash('sha256', mb_strtolower($name, 'UTF-8')),
                0,
                10,
            );
            $resolvedSlug = rtrim(substr($slug, 0, 149), '-').'-'.$suffix;

            if ($connection->fetchOne(
                'SELECT id FROM tags WHERE slug = :slug',
                ['slug' => $resolvedSlug],
            ) !== false) {
                throw new \DomainException(
                    'Keyword operation could not allocate a unique stable slug.',
                );
            }
        }

        $tagId = Uuid::v7();
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $connection->insert('tags', [
            'id' => $tagId->toRfc4122(),
            'slug' => $resolvedSlug,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $tagId;
    }
}
