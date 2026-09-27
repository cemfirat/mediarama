<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\PlatformSettings;

interface PlatformSettingsRepository
{
    public function current(): PlatformSettings;

    public function applyProfile(DeploymentProfile $profile): PlatformSettings;

    public function save(PlatformSettings $settings): void;
}
