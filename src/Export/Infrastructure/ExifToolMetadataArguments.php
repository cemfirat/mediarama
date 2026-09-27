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
        ];

        $allowed = $policy->profile === MetadataExportProfile::Custom
            ? array_flip($policy->includedFields)
            : null;

        foreach ($fields as $name => [$prefix, $value]) {
            if ($value === null || ($allowed !== null && !isset($allowed[$name]))) {
                continue;
            }

            $arguments[] = $prefix.$value;
        }

        if ($policy->profile !== MetadataExportProfile::PrivacySafe
            && $policy->profile !== MetadataExportProfile::Custom) {
            if ($media->latitude !== null) {
                $arguments[] = '-XMP-exif:GPSLatitude='.$media->latitude;
            }
            if ($media->longitude !== null) {
                $arguments[] = '-XMP-exif:GPSLongitude='.$media->longitude;
            }
        }

        return $arguments;
    }
}
