<?php

declare(strict_types=1);

namespace Mediarama\Media\Application;

use Mediarama\Media\Domain\MediaType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ProcessMediaHandler
{
    public function __construct(
        private InspectMediaMetadata $metadata,
        private GenerateImageDerivatives $images,
        private InspectImageGeometry $geometry,
        private InspectVideoProperties $videoProperties,
        private GenerateVideoDerivatives $videos,
        private MediaAssetRepository $media,
    ) {
    }

    public function __invoke(ProcessMedia $message): void
    {
        $id = Uuid::fromString($message->mediaId);
        $asset = $this->media->get($id);

        if ($asset->processingState->value === 'ready') {
            return;
        }

        try {
            ($this->metadata)($id);

            $asset = $this->media->get($id);

            if ($asset->mediaType === MediaType::Image) {
                $geometry = ($this->geometry)($asset);
                $asset->setImageGeometry($geometry['width'], $geometry['height']);
                $this->media->save($asset);

                ($this->images)($asset);
            } elseif ($asset->mediaType === MediaType::Video) {
                $properties = ($this->videoProperties)($asset);
                $asset->setVideoProperties(
                    $properties->width,
                    $properties->height,
                    $properties->durationMs,
                );
                $this->media->save($asset);

                ($this->videos)($asset);
            }

            $asset->markReady();
            $this->media->save($asset);
        } catch (\Throwable $error) {
            $asset = $this->media->get($id);
            $asset->markFailed();
            $this->media->save($asset);

            throw $error;
        }
    }
}
