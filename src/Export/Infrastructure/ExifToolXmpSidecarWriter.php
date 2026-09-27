<?php

declare(strict_types=1);

namespace Mediarama\Export\Infrastructure;

use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Application\MetadataSidecarWriter;
use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Infrastructure\Metadata\ExifToolProcess;

final readonly class ExifToolXmpSidecarWriter implements MetadataSidecarWriter
{
    private const RAW_EXTENSIONS = [
        '3fr',
        'ari',
        'arw',
        'cr2',
        'cr3',
        'crw',
        'dcr',
        'dng',
        'erf',
        'fff',
        'iiq',
        'kdc',
        'mef',
        'mos',
        'mrw',
        'nef',
        'nrw',
        'orf',
        'pef',
        'raf',
        'raw',
        'rw2',
        'rwl',
        'sr2',
        'srf',
        'srw',
        'x3f',
    ];

    public function __construct(
        private ExifToolProcess $process,
        private ExifToolMetadataArguments $arguments,
    ) {
    }

    public function supports(MediaAsset $media): bool
    {
        $extension = strtolower((string) pathinfo($media->originalFilename, PATHINFO_EXTENSION));

        return in_array($extension, self::RAW_EXTENSIONS, true);
    }

    public function write(MediaAsset $media, MetadataExportPolicy $policy)
    {
        if ($policy->profile === MetadataExportProfile::Original) {
            throw new \DomainException('The original export profile does not produce an XMP sidecar.');
        }

        if (!$this->supports($media)) {
            throw new \DomainException(sprintf(
                'XMP sidecar export is not supported for original filename "%s".',
                $media->originalFilename,
            ));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mediarama-xmp-sidecar-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate XMP sidecar temporary path.');
        }

        @unlink($temporary);
        $path = $temporary.'.xmp';

        try {
            $this->process->run([
                '-overwrite_original',
                '-XMP-xmp:CreatorTool=Mediarama',
                ...$this->arguments->build($media, $policy),
                '--',
                $path,
            ]);

            $result = fopen($path, 'rb');
            if ($result === false) {
                throw new \RuntimeException('Unable to open generated XMP sidecar.');
            }

            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                fclose($result);
                throw new \RuntimeException('Unable to allocate XMP sidecar output stream.');
            }

            stream_copy_to_stream($result, $stream);
            fclose($result);
            rewind($stream);

            return $stream;
        } finally {
            @unlink($temporary);
            @unlink($path);
        }
    }
}
