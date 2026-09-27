<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Upload;

use Mediarama\Upload\Application\UploadProblem;
use Symfony\Component\Uid\Uuid;

final class UploadRequestId
{
    public static function parse(string $value): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\Throwable) {
            throw UploadProblem::request(
                'invalid_upload_request',
                'Upload identifier is invalid.',
            );
        }
    }
}
