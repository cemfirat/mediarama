<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

final class UploadQuotaExceeded extends \DomainException
{
    public function __construct(
        public readonly int $limitBytes,
        public readonly int $committedBytes,
        public readonly int $reservedBytes,
        public readonly int $requestedBytes,
    ) {
        parent::__construct('Upload quota exceeded.');
    }
}
