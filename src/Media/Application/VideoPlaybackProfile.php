<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

final readonly class VideoPlaybackProfile
{
    public function __construct(
        public string $name,
        public int $maximumWidth,
        public int $maximumHeight,
        public string $videoCodec,
        public int $crf,
        public string $preset,
        public string $audioCodec,
        public string $audioBitrate,
    ) {
        if (
            trim($name) === ''
            || $maximumWidth < 1
            || $maximumHeight < 1
            || $crf < 0
            || $crf > 51
            || preg_match('/^[A-Za-z0-9_]+$/D', $videoCodec) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/D', $audioCodec) !== 1
            || preg_match('/^[A-Za-z0-9_-]+$/D', $preset) !== 1
            || preg_match('/^[0-9]+[kKmM]?$/D', $audioBitrate) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid video playback profile.');
        }
    }
}
