<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationProducerKind: string
{
    case Metadata = 'metadata';
    case AiExternal = 'ai_external';
    case AiLocal = 'ai_local';
}
