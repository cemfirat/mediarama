<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Probe;

use Mediarama\Media\Application\InspectImageFileGeometry;
use Mediarama\Media\Application\ValidateStoredMediaStructure;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
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
        $path = $this->storage->localPath($object);

        if (!is_file($path) || !is_readable($path)) {
            throw new \DomainException('Uploaded media is not available for structural validation.');
        }

        match ($mediaType) {
            MediaType::Image => $this->validateImage($path),
            MediaType::Audio, MediaType::Video => $this->validateAv($path, $mediaType),
            MediaType::Document => throw new \DomainException('Generic document uploads are not enabled.'),
        };
    }

    private function validateImage(string $path): void
    {
        try {
            ($this->imageGeometry)($path);
        } catch (\RuntimeException $error) {
            throw new \DomainException('Uploaded image failed structural validation.', 0, $error);
        }
    }

    private function validateAv(string $path, MediaType $mediaType): void
    {
        try {
            $streamTypes = $this->ffprobe->streamTypes($path);
        } catch (\RuntimeException $error) {
            throw new \DomainException(
                sprintf('Uploaded %s failed structural validation.', $mediaType->value),
                0,
                $error,
            );
        }

        if (!in_array($mediaType->value, $streamTypes, true)) {
            throw new \DomainException(sprintf(
                'Uploaded %s does not contain a %s stream.',
                $mediaType->value,
                $mediaType->value,
            ));
        }
    }
}
