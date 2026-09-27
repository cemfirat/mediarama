<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

final class UploadQuotaExceeded extends UploadProblem
{
    public function __construct(
        public readonly int $limitBytes,
        public readonly int $committedBytes,
        public readonly int $reservedBytes,
        public readonly int $requestedBytes,
    ) {
        parent::__construct(
            publicCode: 'upload_quota_exceeded',
            retryable: false,
            safeDetails: [
                'limit_bytes' => $limitBytes,
                'committed_bytes' => $committedBytes,
                'reserved_bytes' => $reservedBytes,
                'requested_bytes' => $requestedBytes,
            ],
            internalMessage: 'Upload quota exceeded.',
        );
    }
}
