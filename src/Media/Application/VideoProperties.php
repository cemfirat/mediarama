<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

final readonly class VideoProperties
{
    public function __construct(
        public int $width,
        public int $height,
        public ?int $durationMs,
        public bool $hasAudio,
        public string $videoCodec,
        public ?string $audioCodec,
        public ?string $container,
    ) {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Video dimensions must be positive.');
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw new \InvalidArgumentException('Video duration must not be negative.');
        }

        if (trim($videoCodec) === '') {
            throw new \InvalidArgumentException('Video codec must not be empty.');
        }
    }
}
