<?php

declare(strict_types=1);

namespace Mediarama\Platform\Domain;

final readonly class PlatformSettings
{
    public function __construct(
        public bool $publicPublishingEnabled,
        public SearchIndexPolicy $searchIndexDefault,
    ) {
        if ($searchIndexDefault === SearchIndexPolicy::Inherit) {
            throw new \InvalidArgumentException('Site search-index default must be index or noindex.');
        }
    }
}
