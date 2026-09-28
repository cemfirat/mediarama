<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationRunStatus: string
{
    case Draft = 'draft';
    case ReadyForReview = 'ready_for_review';
    case NoSuggestions = 'no_suggestions';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
