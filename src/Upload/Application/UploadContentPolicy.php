<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadFailureStage;

final readonly class UploadContentPolicy
{
    /** @param list<string> $allowedMimeTypes */
    public function __construct(private array $allowedMimeTypes)
    {
    }

    public function assertAllowed(InspectedContent $content): void
    {
        $this->assertMimeAllowed($content->mimeType);
    }

    public function assertMimeAllowed(string $mimeType): void
    {
        if (!in_array($mimeType, $this->allowedMimeTypes, true)) {
            throw UploadProblem::terminal(
                'media_type_not_allowed',
                UploadFailureStage::Finalization,
                sprintf('MIME type "%s" is not allowed for upload.', $mimeType),
            );
        }

        if (!str_starts_with($mimeType, 'image/')
            && !str_starts_with($mimeType, 'video/')
            && !str_starts_with($mimeType, 'audio/')) {
            throw UploadProblem::terminal(
                'media_type_not_allowed',
                UploadFailureStage::Finalization,
                'Generic document uploads are not enabled.',
            );
        }
    }
}
