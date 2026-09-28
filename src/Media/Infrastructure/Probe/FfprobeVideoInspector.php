<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Probe;

use Mediarama\Media\Application\InspectVideoProperties;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\VideoProperties;
use Mediarama\Media\Domain\MediaAsset;

final readonly class FfprobeVideoInspector implements InspectVideoProperties
{
    public function __construct(
        private MediaStorage $storage,
        private FfprobeProcess $ffprobe,
    ) {
    }

    public function __invoke(MediaAsset $media): VideoProperties
    {
        $source = $this->storage->read($media->original);
        $temporary = tempnam(sys_get_temp_dir(), 'mediarama-video-probe-');

        if ($temporary === false) {
            fclose($source);
            throw new \RuntimeException('Unable to allocate video probe input.');
        }

        try {
            $target = fopen($temporary, 'wb');
            if ($target === false) {
                fclose($source);
                throw new \RuntimeException('Unable to open video probe input.');
            }

            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw new \RuntimeException('Unable to copy video source for probing.');
                }
            } finally {
                fclose($target);
                fclose($source);
            }

            return $this->ffprobe->videoProperties($temporary);
        } finally {
            @unlink($temporary);
        }
    }
}
