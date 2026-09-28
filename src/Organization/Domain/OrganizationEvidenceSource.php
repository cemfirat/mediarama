<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationEvidenceSource: string
{
    case Metadata = 'metadata';
    case Inference = 'inference';
}
