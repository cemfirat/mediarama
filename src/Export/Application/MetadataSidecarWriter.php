<?php

declare(strict_types=1);

namespace Mediarama\Export\Application;

use Mediarama\Media\Domain\MediaAsset;

interface MetadataSidecarWriter
{
    public function supports(MediaAsset $media): bool;

    /**
     * Writes an XMP sidecar artifact without reading or mutating the immutable
     * Mediarama original.
     *
     * @return resource
     */
    public function write(MediaAsset $media, MetadataExportPolicy $policy);
}
