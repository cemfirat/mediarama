<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationProposalStatus: string
{
    case PendingReview = 'pending_review';
    case Rejected = 'rejected';
    case Applied = 'applied';
    case Invalidated = 'invalidated';
}
