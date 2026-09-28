<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationAiPreflightStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
