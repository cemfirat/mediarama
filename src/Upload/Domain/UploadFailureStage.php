<?php

declare(strict_types=1);

namespace Mediarama\Upload\Domain;

enum UploadFailureStage: string
{
    case Acquisition = 'acquisition';
    case Assembly = 'assembly';
    case Validation = 'validation';
    case Finalization = 'finalization';
}
