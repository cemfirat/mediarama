<?php

declare(strict_types=1);

namespace Mediarama\Export\Application;

use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Media\Domain\MediaAsset;

final readonly class ExportMetadataSidecar
{
    public function __construct(private MetadataSidecarWriter $writer)
    {
    }

    /** @return resource */
    public function __invoke(MediaAsset $media, MetadataExportPolicy $policy)
    {
        if ($policy->profile === MetadataExportProfile::Original) {
            throw new \DomainException(
                'The original export profile returns original media bytes, not an XMP sidecar.',
            );
        }

        if (!$this->writer->supports($media)) {
            throw new \DomainException(sprintf(
                'XMP sidecar export is not supported for original filename "%s".',
                $media->originalFilename,
            ));
        }

        return $this->writer->write($media, $policy);
    }
}
