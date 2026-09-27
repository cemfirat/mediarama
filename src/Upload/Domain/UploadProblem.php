<?php

declare(strict_types=1);

namespace Mediarama\Upload\Domain;

final class UploadProblem extends \RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly string $publicMessage,
        public readonly bool $retryable,
        public readonly ?UploadFailureCode $failureCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }

    public static function fromFailure(
        UploadFailureCode $code,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            $code->value,
            $code->publicMessage(),
            $code->isRetryable(),
            $code,
            $previous,
        );
    }

    public static function sessionNotFound(): self
    {
        return new self(
            'upload_not_found',
            'The upload session was not found.',
            false,
        );
    }

    public static function expired(): self
    {
        return new self(
            'upload_expired',
            'The upload session has expired.',
            false,
        );
    }

    public static function invalidState(): self
    {
        return new self(
            'upload_invalid_state',
            'The upload session is not in a state that accepts this operation.',
            false,
        );
    }

    public static function destinationForbidden(): self
    {
        return new self(
            'upload_destination_forbidden',
            'The upload destination is not available to the current user.',
            false,
        );
    }

    public static function assetSizeInvalid(): self
    {
        return new self(
            'upload_asset_size_invalid',
            'The requested upload size is not accepted.',
            false,
        );
    }

    public static function invalidChunkMetadata(): self
    {
        return new self(
            'upload_chunk_metadata_invalid',
            'The upload chunk metadata is invalid.',
            true,
        );
    }

    public static function abandonUnavailable(?\Throwable $previous = null): self
    {
        return new self(
            'upload_abandon_unavailable',
            'The upload cannot be abandoned right now. Retry the request.',
            true,
            null,
            $previous,
        );
    }

    public function stage(): ?UploadFailureStage
    {
        return $this->failureCode?->stage();
    }
}
