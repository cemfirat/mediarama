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
