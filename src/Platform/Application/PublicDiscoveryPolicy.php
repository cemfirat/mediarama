<?php

declare(strict_types=1);

namespace Mediarama\Platform\Application;

use Symfony\Component\Uid\Uuid;

final readonly class PublicDiscoveryPolicy
{
    public function __construct(
        private PlatformSettingsRepository $settings,
        private PublicIndexSubjectRepository $subjects,
    ) {
    }

    public function publicPublishingEnabled(): bool
    {
        return $this->settings->get()->publicPublishingEnabled;
    }

    public function siteIndexable(): bool
    {
        $settings = $this->settings->get();

        return $settings->publicPublishingEnabled
            && $settings->searchIndexDefault->resolve($settings->searchIndexDefault);
    }

    public function collectionIndexable(Uuid $collectionId): bool
    {
        $settings = $this->settings->get();
        if (!$settings->publicPublishingEnabled) {
            return false;
        }

        $policy = $this->subjects->collectionPolicy($collectionId);

        return $policy !== null && $policy->resolve($settings->searchIndexDefault);
    }

    public function mediaIndexable(Uuid $mediaId): bool
    {
        $settings = $this->settings->get();
        if (!$settings->publicPublishingEnabled) {
            return false;
        }

        $policy = $this->subjects->mediaPolicy($mediaId);

        return $policy !== null && $policy->resolve($settings->searchIndexDefault);
    }
}
