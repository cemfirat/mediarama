<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

final readonly class VideoPosterProfile
{
    public function __construct(
        public string $name,
        public int $maximumWidth,
        public int $maximumHeight,
        public int $qualityScale = 3,
    ) {
        if (
            trim($name) === ''
            || $maximumWidth < 1
            || $maximumHeight < 1
            || $qualityScale < 2
            || $qualityScale > 31
        ) {
            throw new \InvalidArgumentException('Invalid video poster profile.');
        }
    }
}
