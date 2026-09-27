<?php

declare(strict_types=1);

namespace Mediarama\Upload\Domain;

use DateTimeImmutable;

final readonly class UploadFailure
{
    public function __construct(
        public string $code,
        public UploadFailureStage $stage,
        public bool $retryable,
        public DateTimeImmutable $failedAt,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{2,79}$/', $code)) {
            throw new \InvalidArgumentException('Upload failure code is invalid.');
        }
    }
}
