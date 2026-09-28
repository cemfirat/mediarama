<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

interface OrganizationAiPresentationGateway
{
    public function presentation(
        Uuid $mediaId,
    ): ?OrganizationAiPresentationAsset;
}
