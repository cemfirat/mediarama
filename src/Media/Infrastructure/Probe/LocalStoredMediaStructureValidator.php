<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Probe;

use Mediarama\Media\Application\InspectImageFileGeometry;
use Mediarama\Media\Application\ValidateStoredMediaStructure;
use Mediarama\Media\Domain\MediaToolRejected;
use Mediarama\Media\Domain\MediaToolUnavailable;
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
            throw new MediaToolUnavailable('Uploaded media is not available for structural validation.');
        }

        match ($mediaType) {
            MediaType::Image => ($this->imageGeometry)($path),
            MediaType::Audio, MediaType::Video => $this->validateAv($path, $mediaType),
            MediaType::Document => throw new MediaToolRejected('Generic document uploads are not enabled.'),
        };
    }

    private function validateAv(string $path, MediaType $mediaType): void
    {
        $streamTypes = $this->ffprobe->streamTypes($path);

        if (!in_array($mediaType->value, $streamTypes, true)) {
            throw new MediaToolRejected(sprintf(
                'Uploaded %s does not contain a %s stream.',
                $mediaType->value,
                $mediaType->value,
            ));
        }
    }
}
