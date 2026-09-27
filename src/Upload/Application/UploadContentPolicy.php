<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Media\Domain\MediaType;
use Mediarama\Upload\Domain\UploadFailureCode;
use Mediarama\Upload\Domain\UploadProblem;

final readonly class UploadContentPolicy
{
    /** @param list<string> $allowedMimeTypes */
    public function __construct(private array $allowedMimeTypes)
    {
    }

    public function assertAllowed(InspectedContent $content): void
    {
        if (!in_array($content->mimeType, $this->allowedMimeTypes, true)) {
            throw UploadProblem::fromFailure(UploadFailureCode::MediaTypeNotAllowed);
        }

        if ($content->mediaType === MediaType::Document) {
            throw UploadProblem::fromFailure(UploadFailureCode::MediaTypeNotAllowed);
        }
    }
}
