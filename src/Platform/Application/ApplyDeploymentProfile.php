<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Mediarama\Platform\Domain\DeploymentProfile;
use Mediarama\Platform\Domain\PlatformSettings;
use Mediarama\Platform\Domain\SearchIndexPolicy;

final readonly class ApplyDeploymentProfile
{
    public function __construct(private PlatformSettingsRepository $settings)
    {
    }

    public function __invoke(DeploymentProfile $profile): PlatformSettings
    {
        $settings = match ($profile) {
            DeploymentProfile::PrivateWorkspace,
            DeploymentProfile::InternalIsolated => new PlatformSettings(
                publicPublishingEnabled: false,
                searchIndexDefault: SearchIndexPolicy::NoIndex,
            ),
            DeploymentProfile::PublicPublishing => new PlatformSettings(
                publicPublishingEnabled: true,
                searchIndexDefault: SearchIndexPolicy::Index,
            ),
        };

        $this->settings->save($settings);

        return $settings;
    }
}
