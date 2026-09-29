<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Metadata;

use DateTimeImmutable;
use Mediarama\Media\Application\InspectedMetadata;

final class ExifToolMetadataParser
{
    public function parse(string $json): InspectedMetadata
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
            throw new \UnexpectedValueException('ExifTool returned an unexpected JSON document.');
        }

        /** @var array<string, mixed> $raw */
        $raw = $decoded[0];

        return new InspectedMetadata(
            embedded: $this->groupEmbeddedMetadata($raw),
            capturedAt: $this->date($this->first($raw, [
                'Composite:SubSecDateTimeOriginal',
                'ExifIFD:DateTimeOriginal',
                'XMP-exif:DateTimeOriginal',
                'EXIF:DateTimeOriginal',
                'XMP-photoshop:DateCreated',
                'XMP:DateCreated',
                'IPTC:DateCreated',
            ])),
            title: $this->string($this->first($raw, ['XMP-dc:Title', 'XMP:Title', 'IPTC:ObjectName'])),
            description: $this->string($this->first($raw, ['XMP-dc:Description', 'XMP:Description', 'IPTC:Caption-Abstract', 'IFD0:ImageDescription', 'EXIF:ImageDescription'])),
            creator: $this->string($this->first($raw, ['XMP-dc:Creator', 'XMP:Creator', 'IPTC:By-line', 'IFD0:Artist', 'EXIF:Artist'])),
            copyright: $this->string($this->first($raw, ['XMP-dc:Rights', 'XMP:Rights', 'IPTC:CopyrightNotice', 'IFD0:Copyright', 'EXIF:Copyright'])),
            cameraMake: $this->string($this->first($raw, ['IFD0:Make', 'EXIF:Make', 'XMP-tiff:Make'])),
            cameraModel: $this->string($this->first($raw, ['IFD0:Model', 'EXIF:Model', 'XMP-tiff:Model'])),
            lens: $this->string($this->first($raw, ['ExifIFD:LensModel', 'EXIF:LensModel', 'XMP-aux:Lens', 'Composite:LensID'])),
            iso: $this->integer($this->first($raw, ['ExifIFD:ISO', 'EXIF:ISO', 'XMP-exif:ISO'])),
            aperture: $this->string($this->first($raw, ['ExifIFD:FNumber', 'EXIF:FNumber', 'XMP-exif:FNumber', 'Composite:Aperture'])),
            exposureTime: $this->string($this->first($raw, ['ExifIFD:ExposureTime', 'EXIF:ExposureTime', 'XMP-exif:ExposureTime'])),
            focalLength: $this->string($this->first($raw, ['ExifIFD:FocalLength', 'EXIF:FocalLength', 'XMP-exif:FocalLength'])),
            latitude: $this->float($this->first($raw, ['Composite:GPSLatitude', 'GPS:GPSLatitude', 'EXIF:GPSLatitude', 'XMP-exif:GPSLatitude'])),
            longitude: $this->float($this->first($raw, ['Composite:GPSLongitude', 'GPS:GPSLongitude', 'EXIF:GPSLongitude', 'XMP-exif:GPSLongitude'])),
            locationName: $this->string($this->first($raw, ['XMP-iptcCore:Location', 'XMP:Location', 'IPTC:Sub-location'])),
            keywords: $this->strings($this->first($raw, ['XMP-dc:Subject', 'XMP:Subject', 'IPTC:Keywords'])),
        );
    }

    /** @param array<string, mixed> $raw */
    private function groupEmbeddedMetadata(array $raw): array
    {
        $grouped = ['exif' => [], 'iptc' => [], 'xmp' => [], 'icc' => [], 'technical' => []];

        foreach ($raw as $tag => $value) {
            if ($tag === 'SourceFile' || str_ends_with($tag, ':SourceFile')) {
                continue;
            }

            $group = str_contains($tag, ':') ? explode(':', $tag, 2)[0] : 'Technical';
            $bucket = $this->bucketForGroup($group);

            // Preserve the group-qualified tag key. Family 1 identifies the
            // namespace/IFD and family 4 keeps duplicate JSON keys unique.
            $grouped[$bucket][$tag] = $value;
        }

        return $grouped;
    }

    private function bucketForGroup(string $group): string
    {
        if (preg_match('/^(?:EXIF|IFD\d+|ExifIFD|GPS|InteropIFD|SubIFD\d*|MakerNotes)$/i', $group) === 1) {
            return 'exif';
        }

        if (preg_match('/^IPTC(?:#|-|$)/i', $group) === 1) {
            return 'iptc';
        }

        if (preg_match('/^XMP(?:-|$)/i', $group) === 1) {
            return 'xmp';
        }

        if (preg_match('/^ICC(?:_|-|$)/i', $group) === 1) {
            return 'icc';
        }

        return 'technical';
    }

    /** @param array<string, mixed> $raw @param list<string> $keys */
    private function first(array $raw, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw)) {
                return $raw[$key];
            }

            if (!str_contains($key, ':')) {
                continue;
            }

            [$group, $name] = explode(':', $key, 2);
            $copyPattern = sprintf(
                '/^%s:Copy\\d+:%s$/i',
                preg_quote($group, '/'),
                preg_quote($name, '/'),
            );

            foreach ($raw as $rawKey => $value) {
                if (preg_match($copyPattern, $rawKey) === 1) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $values,
        ), static fn (string $item): bool => $item !== ''));
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        foreach (['Y:m:d H:i:sP', 'Y:m:d H:i:s', DateTimeImmutable::ATOM, '!Y:m:d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }
}
