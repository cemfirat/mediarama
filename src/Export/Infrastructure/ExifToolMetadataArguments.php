<?php

declare(strict_types=1);

namespace Mediarama\Export\Infrastructure;

use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Media\Domain\MediaAsset;

final readonly class ExifToolMetadataArguments
{
    /** @return list<string> */
    public function build(MediaAsset $media, MetadataExportPolicy $policy): array
    {
        if ($policy->profile === MetadataExportProfile::Original) {
            throw new \DomainException('The original export profile does not write metadata.');
        }

        if ($policy->profile === MetadataExportProfile::PrivacySafe) {
            $arguments = [
                '-GPS:all=',
                '-XMP-exif:GPSLatitude=',
                '-XMP-exif:GPSLongitude=',
                // A human-readable location can be as precise as a home, school,
                // venue or street address. Privacy-safe therefore removes it rather
                // than guessing a coarse location from the same source data.
                '-XMP-iptcCore:Location=',
                '-IPTC:Sub-location=',
                '-SerialNumber=',
                '-InternalSerialNumber=',
            ];
        } else {
            $arguments = [];
        }

        $fields = [
            'title' => ['-XMP-dc:Title=', $media->title],
            'description' => ['-XMP-dc:Description=', $media->description],
            'creator' => ['-XMP-dc:Creator=', $media->creator],
            'copyright' => ['-XMP-dc:Rights=', $media->copyright],
            'location_name' => ['-XMP-iptcCore:Location=', $media->locationName],
            'latitude' => ['-XMP-exif:GPSLatitude=', $media->latitude],
            'longitude' => ['-XMP-exif:GPSLongitude=', $media->longitude],
        ];

        $allowed = $policy->profile === MetadataExportProfile::Custom
            ? array_flip($policy->includedFields)
            : null;

        foreach ($fields as $name => [$prefix, $value]) {
            if (
                $value === null
                || ($allowed !== null && !isset($allowed[$name]))
                || (
                    $policy->profile === MetadataExportProfile::PrivacySafe
                    && in_array($name, ['location_name', 'latitude', 'longitude'], true)
                )
            ) {
                continue;
            }

            $arguments[] = $prefix.$value;
        }

        return $arguments;
    }
}
