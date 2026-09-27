<?php

declare(strict_types=1);

namespace Mediarama\Export\Infrastructure;

/**
 * ExifTool arguments that remove inherited source metadata from a generated
 * Privacy-safe copy while preserving rendering-relevant color/orientation data.
 *
 * This is intentionally separate from ExifToolMetadataArguments: RAW sidecars
 * are new metadata artifacts and do not inherit an original container.
 */
final readonly class ExifToolPrivacySafeCopyArguments
{
    /** @return list<string> */
    public function build(): array
    {
        return [
            // Start from an allowlist boundary instead of trying to predict every
            // potentially identifying EXIF/IPTC/XMP tag a source may contain.
            '-all=',

            // ExifTool explicitly warns that removing ICC/color-space metadata can
            // alter image appearance. Keep ICC in place and copy the standard
            // ColorSpaceTags back from the pre-edit source snapshot.
            '--ICC_Profile:all',
            '-TagsFromFile',
            '@',
            '-ColorSpaceTags',

            // Orientation is display semantics rather than descriptive identity.
            // Removing it from an unrotated source can visibly rotate the export.
            '-Orientation',

            // Preserve non-identifying print/display density semantics.
            '-XResolution',
            '-YResolution',
            '-ResolutionUnit',
        ];
    }
}
