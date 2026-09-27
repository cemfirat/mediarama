<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Mediarama\Platform\Domain\PlatformSettings;

interface PlatformSettingsRepository
{
    public function get(): PlatformSettings;

    public function save(PlatformSettings $settings): void;
}
