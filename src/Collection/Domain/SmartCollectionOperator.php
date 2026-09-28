<?php

declare(strict_types=1);

namespace Mediarama\Collection\Domain;

enum SmartCollectionOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case In = 'in';
    case GreaterThanOrEqual = 'gte';
    case LessThanOrEqual = 'lte';
    case Between = 'between';
    case Contains = 'contains';
    case HasTag = 'has_tag';
}
