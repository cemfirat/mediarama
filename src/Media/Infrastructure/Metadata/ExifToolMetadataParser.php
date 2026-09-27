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
                'ExifIFD:DateTimeOriginal',
                'XMP-exif:DateTimeOriginal',
                'XMP-photoshop:DateCreated',
                'EXIF:DateTimeOriginal',
                'XMP:DateCreated',
                'IPTC:DateCreated',
            ])),
            title: $this->string($this->first($raw, ['XMP-dc:Title', 'XMP:Title', 'IPTC:ObjectName'])),
            description: $this->string($this->first($raw, [
                'XMP-dc:Description',
                'XMP:Description',
                'IPTC:Caption-Abstract',
                'IFD0:ImageDescription',
                'EXIF:ImageDescription',
            ])),
            creator: $this->string($this->first($raw, [
                'XMP-dc:Creator',
                'XMP:Creator',
                'IPTC:By-line',
                'IFD0:Artist',
                'EXIF:Artist',
            ])),
            copyright: $this->string($this->first($raw, [
                'XMP-dc:Rights',
                'XMP:Rights',
                'IPTC:CopyrightNotice',
                'IFD0:Copyright',
                'EXIF:Copyright',
            ])),
            cameraMake: $this->string($this->first($raw, ['IFD0:Make', 'EXIF:Make'])),
            cameraModel: $this->string($this->first($raw, ['IFD0:Model', 'EXIF:Model'])),
            lens: $this->string($this->first($raw, ['ExifIFD:LensModel', 'EXIF:LensModel', 'Composite:LensID'])),
            iso: $this->integer($this->first($raw, ['ExifIFD:ISO', 'EXIF:ISO'])),
            aperture: $this->string($this->first($raw, ['ExifIFD:FNumber', 'EXIF:FNumber', 'Composite:Aperture'])),
            exposureTime: $this->string($this->first($raw, ['ExifIFD:ExposureTime', 'EXIF:ExposureTime'])),
            focalLength: $this->string($this->first($raw, ['ExifIFD:FocalLength', 'EXIF:FocalLength'])),
            latitude: $this->float($this->first($raw, [
                'Composite:GPSLatitude',
                'GPS:GPSLatitude',
                'XMP-exif:GPSLatitude',
                'EXIF:GPSLatitude',
            ])),
            longitude: $this->float($this->first($raw, [
                'Composite:GPSLongitude',
                'GPS:GPSLongitude',
                'XMP-exif:GPSLongitude',
                'EXIF:GPSLongitude',
            ])),
            locationName: $this->string($this->first($raw, [
                'XMP-iptcCore:Location',
                'XMP:Location',
                'IPTC:Sub-location',
            ])),
            keywords: $this->strings($this->first($raw, ['XMP-dc:Subject', 'XMP:Subject', 'IPTC:Keywords'])),
        );
    }

    /** @param array<string, mixed> $raw */
    private function groupEmbeddedMetadata(array $raw): array
    {
        $grouped = ['exif' => [], 'iptc' => [], 'xmp' => [], 'icc' => [], 'technical' => []];

        foreach ($raw as $tag => $value) {
            if ($tag === 'SourceFile') {
                continue;
            }

            [$group, $name] = str_contains($tag, ':') ? explode(':', $tag, 2) : ['Technical', $tag];
            $bucket = match (true) {
                str_starts_with($group, 'EXIF'),
                preg_match('/^(?:IFD\\d+|ExifIFD|GPS|InteropIFD|SubIFD\\d*)$/', $group) === 1 => 'exif',
                str_starts_with($group, 'IPTC') => 'iptc',
                str_starts_with($group, 'XMP') => 'xmp',
                str_starts_with($group, 'ICC') => 'icc',
                default => 'technical',
            };

            $key = array_key_exists($name, $grouped[$bucket]) ? $group.':'.$name : $name;
            $grouped[$bucket][$key] = $value;
        }

        return $grouped;
    }

    /** @param array<string, mixed> $raw @param list<string> $keys */
    private function first(array $raw, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw)) {
                return $raw[$key];
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

        foreach (['Y:m:d H:i:sP', 'Y:m:d H:i:s', DateTimeImmutable::ATOM] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }
}
