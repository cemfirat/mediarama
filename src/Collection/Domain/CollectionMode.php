<?php

declare(strict_types=1);

namespace Mediarama\Collection\Domain;

enum CollectionMode: string
{
    case Manual = 'manual';
    case Smart = 'smart';
}
