<?php

declare(strict_types=1);

namespace Mediarama\Upload\Domain;

enum UploadFailureCode: string
{
    case ChunkSizeInvalid = 'upload_chunk_size_invalid';
    case ChunkSizeMismatch = 'upload_chunk_size_mismatch';
    case ChunkChecksumMismatch = 'upload_chunk_checksum_mismatch';
    case ChunksIncomplete = 'upload_chunks_incomplete';
    case ChunkStorageUnavailable = 'upload_chunk_storage_unavailable';
    case AssemblyStorageUnavailable = 'upload_assembly_storage_unavailable';
    case InspectionUnavailable = 'upload_inspection_unavailable';
    case MediaTypeNotAllowed = 'upload_media_type_not_allowed';
    case MediaInvalid = 'upload_media_invalid';
    case MediaSizeMismatch = 'upload_media_size_mismatch';
    case FinalizationStorageUnavailable = 'upload_finalization_storage_unavailable';
    case FinalizationSourceMissing = 'upload_finalization_source_missing';
    case IntegrityMismatch = 'upload_integrity_mismatch';
    case FinalizationInterrupted = 'upload_finalization_interrupted';

    public function stage(): UploadFailureStage
    {
        return match ($this) {
            self::ChunkSizeInvalid,
            self::ChunkSizeMismatch,
            self::ChunkChecksumMismatch,
            self::ChunkStorageUnavailable => UploadFailureStage::Acquisition,
            self::ChunksIncomplete,
            self::AssemblyStorageUnavailable => UploadFailureStage::Assembly,
            self::InspectionUnavailable,
            self::MediaTypeNotAllowed,
            self::MediaInvalid,
            self::MediaSizeMismatch => UploadFailureStage::Validation,
            self::FinalizationStorageUnavailable,
            self::FinalizationSourceMissing,
            self::IntegrityMismatch,
            self::FinalizationInterrupted => UploadFailureStage::Finalization,
        };
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::ChunkSizeInvalid,
            self::ChunkSizeMismatch,
            self::ChunkChecksumMismatch,
            self::ChunksIncomplete,
            self::ChunkStorageUnavailable,
            self::AssemblyStorageUnavailable,
            self::InspectionUnavailable,
            self::FinalizationStorageUnavailable,
            self::FinalizationInterrupted => true,
            self::MediaTypeNotAllowed,
            self::MediaInvalid,
            self::MediaSizeMismatch,
            self::FinalizationSourceMissing,
            self::IntegrityMismatch => false,
        };
    }

    public function isTerminal(): bool
    {
        return !$this->isRetryable();
    }

    public function publicMessage(): string
    {
        return match ($this) {
            self::ChunkSizeInvalid => 'The upload chunk size is not accepted.',
            self::ChunkSizeMismatch => 'The received chunk size does not match the request.',
            self::ChunkChecksumMismatch => 'The upload chunk checksum does not match.',
            self::ChunksIncomplete => 'The upload is incomplete or its chunks are out of sequence.',
            self::ChunkStorageUnavailable,
            self::AssemblyStorageUnavailable,
            self::InspectionUnavailable,
            self::FinalizationStorageUnavailable,
            self::FinalizationInterrupted => 'The upload cannot be completed right now. Retry the same upload.',
            self::MediaTypeNotAllowed => 'This media type is not allowed for upload.',
            self::MediaInvalid => 'The uploaded media is not structurally valid.',
            self::MediaSizeMismatch => 'The uploaded media size does not match the upload session.',
            self::FinalizationSourceMissing => 'The upload source is no longer available.',
            self::IntegrityMismatch => 'The finalized upload failed its integrity check.',
        };
    }
}
