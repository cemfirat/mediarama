<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationAppliedEntityType: string
{
    case Collection = 'collection';
    case Tag = 'tag';
    case Media = 'media';
}
