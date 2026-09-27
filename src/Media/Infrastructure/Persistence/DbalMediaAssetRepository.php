<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Mediarama\Media\Application\MediaAssetRepository;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\ModerationState;
use Mediarama\Media\Domain\ProcessingState;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Symfony\Component\Uid\Uuid;

final readonly class DbalMediaAssetRepository implements MediaAssetRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(MediaAsset $media): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO media_assets (
    id, owner_id, storage_disk, storage_key, original_filename, mime_type, media_type,
    byte_size, checksum_sha256, width, height, duration_ms, title, description, captured_at,
    processing_state, moderation_state, metadata, metadata_provenance, creator, copyright,
    camera_make, camera_model, lens, iso, aperture, exposure_time, focal_length,
    latitude, longitude, location_name, search_index_policy, created_at, updated_at
) VALUES (
    :id, :owner_id, :storage_disk, :storage_key, :original_filename, :mime_type, :media_type,
    :byte_size, :checksum_sha256, :width, :height, :duration_ms, :title, :description, :captured_at,
    :processing_state, :moderation_state, CAST(:metadata AS JSONB), CAST(:metadata_provenance AS JSONB),
    :creator, :copyright, :camera_make, :camera_model, :lens, :iso, :aperture, :exposure_time,
    :focal_length, :latitude, :longitude, :location_name, :search_index_policy, :created_at, :updated_at
)
ON CONFLICT (id) DO UPDATE SET
    title = EXCLUDED.title,
    description = EXCLUDED.description,
    captured_at = EXCLUDED.captured_at,
    processing_state = EXCLUDED.processing_state,
    moderation_state = EXCLUDED.moderation_state,
    metadata = EXCLUDED.metadata,
    metadata_provenance = EXCLUDED.metadata_provenance,
    creator = EXCLUDED.creator,
    copyright = EXCLUDED.copyright,
    camera_make = EXCLUDED.camera_make,
    camera_model = EXCLUDED.camera_model,
    lens = EXCLUDED.lens,
    iso = EXCLUDED.iso,
    aperture = EXCLUDED.aperture,
    exposure_time = EXCLUDED.exposure_time,
    focal_length = EXCLUDED.focal_length,
    latitude = EXCLUDED.latitude,
    longitude = EXCLUDED.longitude,
    location_name = EXCLUDED.location_name,
    search_index_policy = EXCLUDED.search_index_policy,
    width = EXCLUDED.width,
    height = EXCLUDED.height,
    duration_ms = EXCLUDED.duration_ms,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'id' => $media->id->toRfc4122(),
                'owner_id' => $media->ownerId?->toRfc4122(),
                'storage_disk' => $media->original->disk,
                'storage_key' => $media->original->key,
                'original_filename' => $media->originalFilename,
                'mime_type' => $media->mimeType,
                'media_type' => $media->mediaType->value,
                'byte_size' => $media->byteSize,
                'checksum_sha256' => $media->checksumSha256,
                'width' => $media->width,
                'height' => $media->height,
                'duration_ms' => $media->durationMs,
                'title' => $media->title,
                'description' => $media->description,
                'captured_at' => $media->capturedAt?->format(DATE_ATOM),
                'processing_state' => $media->processingState->value,
                'moderation_state' => $media->moderationState->value,
                'metadata' => json_encode($media->metadata, JSON_THROW_ON_ERROR),
                'metadata_provenance' => json_encode($media->metadataProvenance, JSON_THROW_ON_ERROR),
                'creator' => $media->creator,
                'copyright' => $media->copyright,
                'camera_make' => $media->cameraMake,
                'camera_model' => $media->cameraModel,
                'lens' => $media->lens,
                'iso' => $media->iso,
                'aperture' => $media->aperture,
                'exposure_time' => $media->exposureTime,
                'focal_length' => $media->focalLength,
                'latitude' => $media->latitude,
                'longitude' => $media->longitude,
                'location_name' => $media->locationName,
                'search_index_policy' => $media->searchIndexPolicy->value,
                'created_at' => $media->createdAt->format(DATE_ATOM),
                'updated_at' => $media->updatedAt->format(DATE_ATOM),
            ],
        );
    }

    public function get(Uuid $id): MediaAsset
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM media_assets WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id->toRfc4122()],
        );

        if ($row === false) {
            throw new \DomainException('Media asset not found.');
        }

        return MediaAsset::reconstitute(
            id: Uuid::fromString((string) $row['id']),
            ownerId: $row['owner_id'] !== null ? Uuid::fromString((string) $row['owner_id']) : null,
            original: new StorageObjectId((string) $row['storage_disk'], (string) $row['storage_key']),
            originalFilename: (string) $row['original_filename'],
            mimeType: (string) $row['mime_type'],
            mediaType: MediaType::from((string) $row['media_type']),
            byteSize: (int) $row['byte_size'],
            checksumSha256: (string) $row['checksum_sha256'],
            processingState: ProcessingState::from((string) $row['processing_state']),
            moderationState: ModerationState::from((string) $row['moderation_state']),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            width: $row['width'] !== null ? (int) $row['width'] : null,
            height: $row['height'] !== null ? (int) $row['height'] : null,
            durationMs: $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
            title: $row['title'] !== null ? (string) $row['title'] : null,
            description: $row['description'] !== null ? (string) $row['description'] : null,
            capturedAt: $row['captured_at'] !== null ? new DateTimeImmutable((string) $row['captured_at']) : null,
            metadata: json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR),
            metadataProvenance: json_decode((string) $row['metadata_provenance'], true, flags: JSON_THROW_ON_ERROR),
            creator: $row['creator'] !== null ? (string) $row['creator'] : null,
            copyright: $row['copyright'] !== null ? (string) $row['copyright'] : null,
            cameraMake: $row['camera_make'] !== null ? (string) $row['camera_make'] : null,
            cameraModel: $row['camera_model'] !== null ? (string) $row['camera_model'] : null,
            lens: $row['lens'] !== null ? (string) $row['lens'] : null,
            iso: $row['iso'] !== null ? (int) $row['iso'] : null,
            aperture: $row['aperture'] !== null ? (string) $row['aperture'] : null,
            exposureTime: $row['exposure_time'] !== null ? (string) $row['exposure_time'] : null,
            focalLength: $row['focal_length'] !== null ? (string) $row['focal_length'] : null,
            latitude: $row['latitude'] !== null ? (float) $row['latitude'] : null,
            longitude: $row['longitude'] !== null ? (float) $row['longitude'] : null,
            locationName: $row['location_name'] !== null ? (string) $row['location_name'] : null,
            searchIndexPolicy: SearchIndexPolicy::from((string) $row['search_index_policy']),
        );
    }
}
