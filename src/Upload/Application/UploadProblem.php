<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadFailureStage;

class UploadProblem extends \DomainException
{
    /** @param array<string, int|string|bool|null> $safeDetails */
    public function __construct(
        public readonly string $publicCode,
        public readonly bool $retryable,
        public readonly ?UploadFailureStage $failureStage = null,
        public readonly bool $terminal = false,
        public readonly array $safeDetails = [],
        string $internalMessage = 'Upload request failed.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($internalMessage, 0, $previous);
    }

    /** @param array<string, int|string|bool|null> $safeDetails */
    public static function request(
        string $publicCode,
        string $internalMessage,
        bool $retryable = false,
        array $safeDetails = [],
    ): self {
        return new self(
            $publicCode,
            $retryable,
            safeDetails: $safeDetails,
            internalMessage: $internalMessage,
        );
    }

    /** @param array<string, int|string|bool|null> $safeDetails */
    public static function retryable(
        string $publicCode,
        UploadFailureStage $stage,
        string $internalMessage,
        array $safeDetails = [],
        ?\Throwable $previous = null,
    ): self {
        return new self(
            $publicCode,
            true,
            $stage,
            false,
            $safeDetails,
            $internalMessage,
            $previous,
        );
    }

    /** @param array<string, int|string|bool|null> $safeDetails */
    public static function terminal(
        string $publicCode,
        UploadFailureStage $stage,
        string $internalMessage,
        array $safeDetails = [],
        ?\Throwable $previous = null,
    ): self {
        return new self(
            $publicCode,
            false,
            $stage,
            true,
            $safeDetails,
            $internalMessage,
            $previous,
        );
    }
}
