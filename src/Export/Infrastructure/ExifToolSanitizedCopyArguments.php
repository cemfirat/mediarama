<?php

declare(strict_types=1);

namespace Mediarama\Export\Infrastructure;

/**
 * ExifTool arguments that remove inherited user/privacy metadata from generated
 * sanitized copies while preserving rendering-critical image/container state.
 *
 * Privacy-safe and Custom copies use this boundary. Current intentionally keeps
 * inherited source metadata, while RAW XMP sidecars are fresh artifacts and do
 * not inherit an original container.
 */
final readonly class ExifToolSanitizedCopyArguments
{
    /** @return list<string> */
    public function build(): array
    {
        return [
            // Start from an allowlist boundary instead of trying to predict every
            // potentially identifying EXIF/IPTC/XMP tag a source may contain.
            '-all=',

            // TIFF IFD0 also contains structural image tags, so ExifTool cannot
            // remove the IFD itself. Clear the documented common descriptive IFD0
            // surface explicitly while preserving the structural container.
            '-CommonIFD0=',

            // ExifTool explicitly warns that removing ICC/color-space metadata can
            // alter image appearance. Keep ICC in place and copy the standard
            // ColorSpaceTags back from the pre-edit source snapshot.
            '--ICC_Profile:all',
            '-TagsFromFile',
            '@',
            '-ColorSpaceTags',

            // Orientation is display semantics rather than descriptive identity.
            '-Orientation',

            // PNG gAMA/sRGB chunks are display semantics but are not covered by
            // ColorSpaceTags on the deployed ExifTool runtime.
            '-PNG:Gamma',
            '-PNG:SRGBRendering',

            // Preserve non-identifying print/display density semantics.
            '-XResolution',
            '-YResolution',
            '-ResolutionUnit',
        ];
    }
}
