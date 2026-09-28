<?php

declare(strict_types=1);

namespace Mediarama\Organization\Application;

use Symfony\Component\Uid\Uuid;

final readonly class OrganizationProposalMediaPreviewResult
{
    public function __construct(
        public Uuid $id,
        public ?string $title,
        public string $mediaType,
    ) {
    }
}
