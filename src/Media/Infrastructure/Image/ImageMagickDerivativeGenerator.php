<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Image;

use DateTimeImmutable;
use Mediarama\Media\Application\ImageDerivativeGenerator;
use Mediarama\Media\Application\ImageDerivativeProfile;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\StorageObjectId;
use Symfony\Component\Uid\Uuid;

final readonly class ImageMagickDerivativeGenerator implements ImageDerivativeGenerator
{
    public function __construct(
        private MediaStorage $storage,
        private ImageMagickProcess $process,
        private ImageMagickWatermarkRenderer $watermarks,
        private CwebpEncoder $webp,
    ) {
    }

    public function generate(MediaAsset $media, ImageDerivativeProfile $profile, int $processingVersion): MediaDerivative
    {
        $source = $this->storage->read($media->original);
        $input = tempnam(sys_get_temp_dir(), 'mediarama-image-in-');
        $output = tempnam(sys_get_temp_dir(), 'mediarama-image-out-');
        $prepared = ($profile->format === 'webp' || $profile->watermark)
            ? tempnam(sys_get_temp_dir(), 'mediarama-image-prepared-')
            : null;
        $intermediate = $profile->watermark
            ? tempnam(sys_get_temp_dir(), 'mediarama-image-watermark-base-')
            : null;

        if (
            $input === false
            || $output === false
            || (($profile->format === 'webp' || $profile->watermark) && $prepared === false)
            || ($profile->watermark && $intermediate === false)
        ) {
            foreach ([$input, $output, $prepared, $intermediate] as $temporary) {
                if (is_string($temporary)) {
                    @unlink($temporary);
                }
            }
            fclose($source);

            throw new \RuntimeException('Unable to allocate image processing files.');
        }

        $outputWithExtension = $output.'.'.$profile->format;

        try {
            $inputHandle = fopen($input, 'wb');
            if ($inputHandle === false) {
                fclose($source);
                throw new \RuntimeException('Unable to open temporary image input.');
            }

            try {
                if (stream_copy_to_stream($source, $inputHandle) === false) {
                    throw new \RuntimeException('Unable to copy image input for processing.');
                }
            } finally {
                fclose($inputHandle);
                fclose($source);
            }

            $metadata = [
                'orientation_normalized' => true,
                'watermarked' => false,
            ];

            if ($profile->watermark) {
                if (!is_string($intermediate) || !is_string($prepared)) {
                    throw new \LogicException('Watermark processing paths were not allocated.');
                }

                $this->process->convert([
                    $input.'[0]',
                    '-auto-orient',
                    '-strip',
                    '-thumbnail',
                    sprintf('%dx%d>', $profile->maximumWidth, $profile->maximumHeight),
                    '-colorspace',
                    'sRGB',
                    'miff:'.$intermediate,
                ]);

                $metadata = [
                    'orientation_normalized' => true,
                    ...$this->watermarks->renderLossless(
                        $intermediate,
                        $prepared,
                    ),
                ];
            } elseif ($profile->format === 'webp') {
                if (!is_string($prepared)) {
                    throw new \LogicException('WebP preparation path was not allocated.');
                }

                $this->process->convert([
                    $input.'[0]',
                    '-auto-orient',
                    '-strip',
                    '-thumbnail',
                    sprintf('%dx%d>', $profile->maximumWidth, $profile->maximumHeight),
                    '-colorspace',
                    'sRGB',
                    'png:'.$prepared,
                ]);
            }

            if ($profile->format === 'webp') {
                if (!is_string($prepared)) {
                    throw new \LogicException('WebP preparation path was not allocated.');
                }

                $this->webp->encode(
                    $prepared,
                    $outputWithExtension,
                    $profile->quality,
                );
                $metadata['encoder'] = 'cwebp';
                $metadata['encoder_quality'] = $profile->quality;
            } elseif ($profile->watermark) {
                if (!is_string($prepared)) {
                    throw new \LogicException('Watermark preparation path was not allocated.');
                }

                $this->process->convert([
                    $prepared.'[0]',
                    '-strip',
                    '-quality',
                    (string) $profile->quality,
                    $outputWithExtension,
                ]);
            } else {
                $this->process->convert([
                    $input.'[0]',
                    '-auto-orient',
                    '-strip',
                    '-thumbnail',
                    sprintf('%dx%d>', $profile->maximumWidth, $profile->maximumHeight),
                    '-quality',
                    (string) $profile->quality,
                    $outputWithExtension,
                ]);
            }

            $imageInfo = getimagesize($outputWithExtension);
            if ($imageInfo === false) {
                throw new \RuntimeException('Generated derivative is not a readable image.');
            }

            $storageId = new StorageObjectId(
                'media',
                sprintf(
                    'derivatives/%s/v%d/%s.%s',
                    $media->id->toRfc4122(),
                    $processingVersion,
                    $profile->name,
                    $profile->format,
                ),
            );

            $stream = fopen($outputWithExtension, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to read generated derivative.');
            }

            try {
                $stored = $this->storage->write($storageId, $stream, $imageInfo['mime'] ?? null);
            } finally {
                fclose($stream);
            }

            $now = new DateTimeImmutable();

            return new MediaDerivative(
                Uuid::v7(),
                $media->id,
                'image',
                $profile->name,
                $processingVersion,
                $storageId,
                (string) ($imageInfo['mime'] ?? 'application/octet-stream'),
                $stored->byteSize,
                (int) $imageInfo[0],
                (int) $imageInfo[1],
                null,
                $metadata,
                $now,
                $now,
            );
        } finally {
            @unlink($input);
            @unlink($output);
            @unlink($outputWithExtension);
            if (is_string($prepared)) {
                @unlink($prepared);
            }
            if (is_string($intermediate)) {
                @unlink($intermediate);
            }
        }
    }
}
