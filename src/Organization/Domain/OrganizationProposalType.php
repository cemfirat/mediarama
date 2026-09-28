<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationProposalType: string
{
    case SmartCollection = 'smart_collection';
    case ManualCollection = 'manual_collection';
    case Tag = 'tag';
    case ReviewBucket = 'review_bucket';
    case TitleDescription = 'title_description';
    case Cover = 'cover';
}
