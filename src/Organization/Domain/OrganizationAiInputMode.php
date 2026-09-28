<?php

declare(strict_types=1);

namespace Mediarama\Organization\Domain;

enum OrganizationAiInputMode: string
{
    case MetadataOnly = 'metadata_only';
    case MetadataAndPresentation = 'metadata_and_presentation';
}
