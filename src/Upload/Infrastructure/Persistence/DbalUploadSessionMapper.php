<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Persistence;

use DateTimeImmutable;
use Mediarama\Upload\Domain\UploadFailureCode;
use Mediarama\Upload\Domain\UploadFailureStage;
use Mediarama\Upload\Domain\UploadSession;
use Mediarama\Upload\Domain\UploadStatus;
use Symfony\Component\Uid\Uuid;

final class DbalUploadSessionMapper
{
    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): UploadSession
    {
        return UploadSession::reconstitute(
            Uuid::fromString((string) $row['id']),
            Uuid::fromString((string) $row['user_id']),
            $row['target_collection_id'] !== null
                ? Uuid::fromString((string) $row['target_collection_id'])
                : null,
            (string) $row['original_filename'],
            (int) $row['expected_size'],
            $row['expected_mime'] !== null ? (string) $row['expected_mime'] : null,
            (string) $row['temporary_storage_key'],
            UploadStatus::from((string) $row['status']),
            new DateTimeImmutable((string) $row['expires_at']),
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
            $row['last_failure_code'] !== null
                ? UploadFailureCode::from((string) $row['last_failure_code'])
                : null,
            $row['last_failure_stage'] !== null
                ? UploadFailureStage::from((string) $row['last_failure_stage'])
                : null,
            self::nullableBoolean($row['last_failure_retryable']),
            $row['last_failed_at'] !== null
                ? new DateTimeImmutable((string) $row['last_failed_at'])
                : null,
        );
    }

    private static function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => throw new \RuntimeException('Invalid integer upload failure retryable value.'),
            };
        }

        return match (strtolower(trim((string) $value))) {
            '0', 'f', 'false', 'off', 'no' => false,
            '1', 't', 'true', 'on', 'yes' => true,
            default => throw new \RuntimeException('Invalid upload failure retryable value.'),
        };
    }
}
