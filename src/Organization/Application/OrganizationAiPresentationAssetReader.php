<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

interface OrganizationAiPresentationAssetReader
{
    /**
     * @param list<Uuid> $mediaIds
     * @return list<Uuid>
     */
    public function available(
        Uuid $requesterId,
        array $mediaIds,
    ): array;

    public function read(
        Uuid $requesterId,
        Uuid $mediaId,
    ): OrganizationAiPresentationAsset;
}
