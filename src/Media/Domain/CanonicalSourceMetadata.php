<?php

declare(strict_types=1);

namespace Mediarama\Media\Domain;

final class CanonicalSourceMetadata
{
    /** @var array<string,list<string>> */
    private const EXIFTOOL_TAGS = [
        'title' => [
            'XMP-dc:Title',
            'XMP:Title',
            'IPTC:ObjectName',
        ],
        'description' => [
            'XMP-dc:Description',
            'XMP:Description',
            'IPTC:Caption-Abstract',
            'IFD0:ImageDescription',
            'EXIF:ImageDescription',
        ],
        'creator' => [
            'XMP-dc:Creator',
            'XMP:Creator',
            'IPTC:By-line',
            'IFD0:Artist',
            'EXIF:Artist',
        ],
        'copyright' => [
            'XMP-dc:Rights',
            'XMP:Rights',
            'IPTC:CopyrightNotice',
            'IFD0:Copyright',
            'EXIF:Copyright',
        ],
        'location_name' => [
            'XMP-iptcCore:Location',
            'XMP:Location',
            'IPTC:Sub-location',
        ],
    ];

    /**
     * The persisted source snapshot groups tags and keeps a namespace prefix
     * only when two tags would otherwise collide. Every accepted legacy key is
     * therefore listed explicitly; arbitrary raw-tag searching is forbidden.
     *
     * @var array<string,list<array{0:string,1:string}>>
     */
    private const SNAPSHOT_TAGS = [
        'title' => [
            ['xmp', 'Title'],
            ['xmp', 'XMP-dc:Title'],
            ['xmp', 'XMP:Title'],
            ['iptc', 'ObjectName'],
            ['iptc', 'IPTC:ObjectName'],
        ],
        'description' => [
            ['xmp', 'Description'],
            ['xmp', 'XMP-dc:Description'],
            ['xmp', 'XMP:Description'],
            ['iptc', 'Caption-Abstract'],
            ['iptc', 'IPTC:Caption-Abstract'],
            ['exif', 'ImageDescription'],
            ['exif', 'IFD0:ImageDescription'],
            ['exif', 'EXIF:ImageDescription'],
        ],
        'creator' => [
            ['xmp', 'Creator'],
            ['xmp', 'XMP-dc:Creator'],
            ['xmp', 'XMP:Creator'],
            ['iptc', 'By-line'],
            ['iptc', 'IPTC:By-line'],
            ['exif', 'Artist'],
            ['exif', 'IFD0:Artist'],
            ['exif', 'EXIF:Artist'],
        ],
        'copyright' => [
            ['xmp', 'Rights'],
            ['xmp', 'XMP-dc:Rights'],
            ['xmp', 'XMP:Rights'],
            ['iptc', 'CopyrightNotice'],
            ['iptc', 'IPTC:CopyrightNotice'],
            ['exif', 'Copyright'],
            ['exif', 'IFD0:Copyright'],
            ['exif', 'EXIF:Copyright'],
        ],
        'location_name' => [
            ['xmp', 'Location'],
            ['xmp', 'XMP-iptcCore:Location'],
            ['xmp', 'XMP:Location'],
            ['iptc', 'Sub-location'],
            ['iptc', 'IPTC:Sub-location'],
        ],
    ];

    /**
     * @param array<string,mixed> $raw
     * @return array{value:string,tag:string}|null
     */
    public static function fromExifTool(array $raw, string $field): ?array
    {
        foreach (self::EXIFTOOL_TAGS[$field] ?? [] as $tag) {
            if (!array_key_exists($tag, $raw)) {
                continue;
            }

            $value = self::string($raw[$tag]);
            if ($value !== null) {
                return ['value' => $value, 'tag' => $tag];
            }
        }

        return null;
    }

    /**
     * Resolve an unambiguous typed value from the persisted source snapshot.
     * Conflicting values fail closed because namespace information may have
     * been collapsed for the first tag in a grouped legacy snapshot.
     *
     * @param array<string,mixed> $metadata
     * @return array{value:string,tag:string}|null
     */
    public static function fromStoredSnapshot(
        array $metadata,
        string $field,
    ): ?array {
        $matches = [];

        foreach (self::SNAPSHOT_TAGS[$field] ?? [] as [$group, $tag]) {
            $bucket = $metadata[$group] ?? null;
            if (!is_array($bucket) || !array_key_exists($tag, $bucket)) {
                continue;
            }

            $value = self::string($bucket[$tag]);
            if ($value === null) {
                continue;
            }

            $matches[] = [
                'value' => $value,
                'tag' => str_contains($tag, ':')
                    ? $tag
                    : strtoupper($group).':'.$tag,
            ];
        }

        if ($matches === []) {
            return null;
        }

        $values = [];
        foreach ($matches as $match) {
            $values[$match['value']] = true;
        }

        if (count($values) !== 1) {
            return null;
        }

        $tags = array_values(array_unique(array_column($matches, 'tag')));

        return [
            'value' => $matches[0]['value'],
            'tag' => implode(' + ', $tags),
        ];
    }

    /** @return list<string> */
    public static function editableFields(): array
    {
        return array_keys(self::EXIFTOOL_TAGS);
    }

    private static function string(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
