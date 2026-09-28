<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

final readonly class OrganizationAiPreflightMedia
{
    public function __construct(
        public Uuid $mediaId,
        public bool $sendPresentation,
    ) {
    }
}
