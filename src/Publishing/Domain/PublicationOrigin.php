<?php

declare(strict_types=1);

namespace Mediarama\Publishing\Domain;

enum PublicationOrigin: string
{
    case Editorial = 'editorial';
    case Imported = 'imported';
}
