<?php

declare(strict_types=1);

namespace Mediarama\Platform\Domain;

enum SearchIndexPolicy: string
{
    case Inherit = 'inherit';
    case Index = 'index';
    case NoIndex = 'noindex';

    public function resolve(self $siteDefault): bool
    {
        if ($siteDefault === self::Inherit) {
            throw new \InvalidArgumentException('Site search-index default cannot inherit.');
        }

        return match ($this) {
            self::Index => true,
            self::NoIndex => false,
            self::Inherit => $siteDefault === self::Index,
        };
    }
}
