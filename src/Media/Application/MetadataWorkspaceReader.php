<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use DateTimeInterface;
use Mediarama\Media\Domain\MediaAsset;
use Symfony\Component\Uid\Uuid;

final readonly class MetadataWorkspaceReader
{
    /** @var array<string,string> */
    private const SOURCE_GROUPS = [
        'exif' => 'EXIF / Camera',
        'iptc' => 'IPTC / Descriptive',
        'xmp' => 'XMP / Workflow',
        'icc' => 'ICC / Color',
        'technical' => 'File / Technical',
    ];

    public function __construct(private MediaAssetRepository $media)
    {
    }

    public function read(Uuid $requesterId, Uuid $mediaId): MetadataWorkspaceView
    {
        $media = $this->media->get($mediaId);

        if ($media->ownerId === null || !$media->ownerId->equals($requesterId)) {
            throw new \DomainException('Metadata workspace is available to the MediaAsset owner only.');
        }

        return new MetadataWorkspaceView(
            id: $media->id,
            originalFilename: $media->originalFilename,
            mimeType: $media->mimeType,
            mediaType: $media->mediaType->value,
            byteSize: $media->byteSize,
            width: $media->width,
            height: $media->height,
            durationMs: $media->durationMs,
            title: $media->title,
            createdAt: $media->createdAt,
            updatedAt: $media->updatedAt,
            currentFields: $this->currentFields($media),
            sourceGroups: $this->sourceGroups($media->metadata),
        );
    }

    /**
     * @return list<array{key:string,label:string,value:?string,provenance:?string,sensitive:bool}>
     */
    private function currentFields(MediaAsset $media): array
    {
        $fields = [
            ['title', 'Title', $media->title, false],
            ['description', 'Description', $media->description, false],
            ['captured_at', 'Captured at', $media->capturedAt, false],
            ['creator', 'Creator', $media->creator, true],
            ['copyright', 'Copyright / rights', $media->copyright, false],
            ['camera_make', 'Camera make', $media->cameraMake, false],
            ['camera_model', 'Camera model', $media->cameraModel, false],
            ['lens', 'Lens', $media->lens, false],
            ['iso', 'ISO', $media->iso, false],
            ['aperture', 'Aperture', $media->aperture, false],
            ['exposure_time', 'Exposure time', $media->exposureTime, false],
            ['focal_length', 'Focal length', $media->focalLength, false],
            ['latitude', 'Latitude', $media->latitude, true],
            ['longitude', 'Longitude', $media->longitude, true],
            ['location_name', 'Location name', $media->locationName, true],
        ];

        $result = [];
        foreach ($fields as [$key, $label, $value, $sensitive]) {
            $formatted = $this->currentValue($value);
            $result[] = [
                'key' => $key,
                'label' => $label,
                'value' => $formatted,
                'provenance' => isset($media->metadataProvenance[$key])
                    ? (string) $media->metadataProvenance[$key]
                    : ($formatted === null ? null : 'not_recorded'),
                'sensitive' => $sensitive,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,array{label:string,tags:list<array{name:string,value:string,sensitive:bool}>}>
     */
    private function sourceGroups(array $metadata): array
    {
        $groups = [];
        foreach (self::SOURCE_GROUPS as $key => $label) {
            $groups[$key] = [
                'label' => $label,
                'tags' => $this->tags($metadata[$key] ?? [], $key),
            ];
        }

        $other = [];
        foreach ($metadata as $key => $value) {
            if (array_key_exists((string) $key, self::SOURCE_GROUPS)) {
                continue;
            }

            $name = (string) $key;
            $other[] = [
                'name' => $name,
                'value' => $this->sourceValue($value),
                'sensitive' => $this->sensitiveSourceTag($name),
            ];
        }

        if ($other !== []) {
            usort(
                $other,
                static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']),
            );
            $groups['other'] = [
                'label' => 'Other source metadata',
                'tags' => $other,
            ];
        }

        return $groups;
    }

    /**
     * @return list<array{name:string,value:string,sensitive:bool}>
     */
    private function tags(mixed $raw, string $group): array
    {
        if (!is_array($raw)) {
            return $raw === null ? [] : [[
                'name' => $group,
                'value' => $this->sourceValue($raw),
                'sensitive' => $this->sensitiveSourceTag($group),
            ]];
        }

        $tags = [];
        foreach ($raw as $name => $value) {
            $name = (string) $name;
            $tags[] = [
                'name' => $name,
                'value' => $this->sourceValue($value),
                'sensitive' => $this->sensitiveSourceTag($name),
            ];
        }

        usort(
            $tags,
            static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']),
        );

        return $tags;
    }

    private function currentValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : $this->sourceValue($value);
    }

    private function sourceValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode(
            $value,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR,
        );
    }

    private function sensitiveSourceTag(string $name): bool
    {
        return preg_match(
            '/(?:gps|latitude|longitude|serial(?:number)?|ownername|creator|person|email|phone|contact|location|address)/i',
            $name,
        ) === 1;
    }
}
