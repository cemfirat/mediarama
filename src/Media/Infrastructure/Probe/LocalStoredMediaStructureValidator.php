<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Probe;

use Mediarama\Media\Application\InspectImageFileGeometry;
use Mediarama\Media\Application\MediaValidationRejected;
use Mediarama\Media\Application\MediaValidationUnavailable;
use Mediarama\Media\Application\ValidateStoredMediaStructure;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Process\MediaToolRejected;
use Mediarama\Media\Infrastructure\Process\MediaToolUnavailable;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;

final readonly class LocalStoredMediaStructureValidator implements ValidateStoredMediaStructure
{
    public function __construct(
        private LocalMediaStorage $storage,
        private InspectImageFileGeometry $imageGeometry,
        private FfprobeProcess $ffprobe,
    ) {
    }

    public function __invoke(StorageObjectId $object, MediaType $mediaType): void
    {
        try {
            $path = $this->storage->localPath($object);
        } catch (\RuntimeException|\DomainException $error) {
            throw new MediaValidationUnavailable(
                'Uploaded media path is temporarily unavailable for structural validation.',
                0,
                $error,
            );
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new MediaValidationUnavailable(
                'Uploaded media is temporarily unavailable for structural validation.',
            );
        }

        match ($mediaType) {
            MediaType::Image => $this->validateImage($path),
            MediaType::Audio, MediaType::Video => $this->validateAv($path, $mediaType),
            MediaType::Document => throw new MediaValidationRejected(
                'Generic document uploads are not enabled.',
            ),
        };
    }

    private function validateImage(string $path): void
    {
        try {
            ($this->imageGeometry)($path);
        } catch (MediaToolRejected $error) {
            throw new MediaValidationRejected(
                'Uploaded image failed structural validation.',
                0,
                $error,
            );
        } catch (MediaToolUnavailable $error) {
            throw new MediaValidationUnavailable(
                'Image structural validation is temporarily unavailable.',
                0,
                $error,
            );
        } catch (\RuntimeException|\DomainException $error) {
            throw new MediaValidationUnavailable(
                'Image structural validation failed unexpectedly.',
                0,
                $error,
            );
        }
    }

    private function validateAv(string $path, MediaType $mediaType): void
    {
        try {
            $streamTypes = $this->ffprobe->streamTypes($path);
        } catch (MediaToolRejected $error) {
            throw new MediaValidationRejected(
                sprintf('Uploaded %s failed structural validation.', $mediaType->value),
                0,
                $error,
            );
        } catch (MediaToolUnavailable $error) {
            throw new MediaValidationUnavailable(
                sprintf('%s structural validation is temporarily unavailable.', ucfirst($mediaType->value)),
                0,
                $error,
            );
        } catch (\RuntimeException|\DomainException $error) {
            throw new MediaValidationUnavailable(
                sprintf('%s structural validation failed unexpectedly.', ucfirst($mediaType->value)),
                0,
                $error,
            );
        }

        if (!in_array($mediaType->value, $streamTypes, true)) {
            throw new MediaValidationRejected(sprintf(
                'Uploaded %s does not contain a %s stream.',
                $mediaType->value,
                $mediaType->value,
            ));
        }
    }
}
