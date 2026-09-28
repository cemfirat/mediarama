<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

interface OrganizationMetadataSnapshotQuery
{
    /**
     * @param list<Uuid> $mediaIds
     * @return list<OrganizationMetadataRecord>
     */
    public function snapshot(
        Uuid $requesterId,
        array $mediaIds,
    ): array;
}
