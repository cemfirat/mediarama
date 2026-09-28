<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaAsset;

interface InspectVideoProperties
{
    public function __invoke(MediaAsset $media): VideoProperties;
}
