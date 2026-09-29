<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Metadata;

use Mediarama\Media\Application\InspectedMetadata;
use Mediarama\Media\Application\MediaMetadataInspector;
use Mediarama\Media\Domain\StorageObjectId;

final readonly class LocalExifToolInspector implements MediaMetadataInspector
{
    public function __construct(
        private ExifToolProcess $process,
        private ExifToolMetadataParser $parser,
        private string $mediaRoot,
    ) {
    }

    public function inspect(StorageObjectId $original): InspectedMetadata
    {
        if ($original->disk !== 'media') {
            throw new \InvalidArgumentException('Local inspector only supports the media disk.');
        }

        $path = rtrim($this->mediaRoot, '/').'/'.$original->key;

        $json = $this->process->run([
            '-json',
            '-struct',
            '-G1:4',
            '-a',
            '-n',
            '--',
            $path,
        ]);

        return $this->parser->parse($json);
    }
}
