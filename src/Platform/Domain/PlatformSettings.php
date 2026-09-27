<?php

declare(strict_types=1);

namespace Mediarama\Platform\Domain;

final readonly class PlatformSettings
{
    public function __construct(
        public DeploymentProfile $deploymentProfile,
        public bool $publicPublishingEnabled,
        public SearchIndexPolicy $searchIndexDefault,
    ) {
        if ($searchIndexDefault === SearchIndexPolicy::Inherit) {
            throw new \InvalidArgumentException('Site search-index default cannot inherit.');
        }
    }

    public static function forProfile(DeploymentProfile $profile): self
    {
        return match ($profile) {
            DeploymentProfile::PublicPublishing => new self(
                $profile,
                true,
                SearchIndexPolicy::Index,
            ),
            DeploymentProfile::PrivateWorkspace,
            DeploymentProfile::InternalIsolated => new self(
                $profile,
                false,
                SearchIndexPolicy::NoIndex,
            ),
        };
    }
}
