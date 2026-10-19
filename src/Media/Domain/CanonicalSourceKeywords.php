<?php

declare(strict_types=1);

namespace Mediarama\Media\Domain;

final class CanonicalSourceKeywords
{
    /** @var list<string> */
    private const EXIFTOOL_TAGS = [
        'XMP-dc:Subject',
        'XMP:Subject',
        'IPTC:Keywords',
    ];

    /**
     * @var list<array{0:string,1:string}>
     */
    private const SNAPSHOT_TAGS = [
        ['xmp', 'Subject'],
        ['xmp', 'XMP-dc:Subject'],
        ['xmp', 'XMP:Subject'],
        ['iptc', 'Keywords'],
        ['iptc', 'IPTC:Keywords'],
    ];

    /**
     * @param array<string,mixed> $raw
     * @return array{values:list<string>,tag:string}|null
     */
    public static function fromExifTool(array $raw): ?array
    {
        foreach (self::EXIFTOOL_TAGS as $tag) {
            if (!array_key_exists($tag, $raw)) {
                continue;
            }

            $values = self::values($raw[$tag]);
            if ($values !== []) {
                return ['values' => $values, 'tag' => $tag];
            }
        }

        return null;
    }

    /**
     * Resolve an unambiguous keyword list from the persisted source snapshot.
     * Equivalent lists may differ in order, but conflicting sets fail closed.
     *
     * @param array<string,mixed> $metadata
     * @return array{values:list<string>,tag:string}|null
     */
    public static function fromStoredSnapshot(array $metadata): ?array
    {
        $matches = [];

        foreach (self::SNAPSHOT_TAGS as [$group, $tag]) {
            $bucket = $metadata[$group] ?? null;
            if (!is_array($bucket) || !array_key_exists($tag, $bucket)) {
                continue;
            }

            $values = self::values($bucket[$tag]);
            if ($values === []) {
                continue;
            }

            $matches[] = [
                'values' => $values,
                'tag' => str_contains($tag, ':')
                    ? $tag
                    : strtoupper($group).':'.$tag,
            ];
        }

        if ($matches === []) {
            return null;
        }

        $signatures = [];
        foreach ($matches as $match) {
            $signatures[self::signature($match['values'])] = true;
        }

        if (count($signatures) !== 1) {
            return null;
        }

        $tags = array_values(array_unique(array_column($matches, 'tag')));

        return [
            'values' => $matches[0]['values'],
            'tag' => implode(' + ', $tags),
        ];
    }

    /** @return list<string> */
    private static function values(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $values = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            $key = mb_strtolower($item, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $values[] = $item;
        }

        return $values;
    }

    /** @param list<string> $values */
    private static function signature(array $values): string
    {
        $normalized = array_map(
            static fn (string $value): string => mb_strtolower($value, 'UTF-8'),
            $values,
        );
        sort($normalized, SORT_STRING);

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
