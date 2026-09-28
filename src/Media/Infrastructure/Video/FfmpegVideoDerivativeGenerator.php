<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Video;

use DateTimeImmutable;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\VideoPlaybackGenerator;
use Mediarama\Media\Application\VideoPlaybackProfile;
use Mediarama\Media\Application\VideoPosterGenerator;
use Mediarama\Media\Application\VideoPosterProfile;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaDerivative;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Probe\FfprobeProcess;
use Symfony\Component\Uid\Uuid;

final readonly class FfmpegVideoDerivativeGenerator implements VideoPosterGenerator, VideoPlaybackGenerator
{
    public function __construct(
        private MediaStorage $storage,
        private FfmpegProcess $ffmpeg,
        private FfprobeProcess $ffprobe,
    ) {
    }

    public function generate(
        MediaAsset $media,
        VideoPosterProfile|VideoPlaybackProfile $profile,
        int $processingVersion,
    ): MediaDerivative {
        if ($processingVersion < 1) {
            throw new \InvalidArgumentException('Video derivative version must be positive.');
        }

        return $profile instanceof VideoPosterProfile
            ? $this->generatePoster($media, $profile, $processingVersion)
            : $this->generatePlayback($media, $profile, $processingVersion);
    }

    private function generatePoster(
        MediaAsset $media,
        VideoPosterProfile $profile,
        int $processingVersion,
    ): MediaDerivative {
        [$input, $output] = $this->temporaryPair('jpg');

        try {
            $this->copyOriginal($media, $input);

            $this->ffmpeg->run([
                '-y',
                '-i', $input,
                '-map', '0:v:0',
                '-frames:v', '1',
                '-vf', $this->scaleFilter($profile->maximumWidth, $profile->maximumHeight),
                '-an',
                '-sn',
                '-dn',
                '-map_metadata', '-1',
                '-q:v', (string) $profile->qualityScale,
                $output,
            ]);

            $imageInfo = getimagesize($output);
            if ($imageInfo === false) {
                throw new \RuntimeException('Generated video poster is not a readable image.');
            }

            $storage = new StorageObjectId(
                'media',
                sprintf(
                    'derivatives/%s/v%d/%s.jpg',
                    $media->id->toRfc4122(),
                    $processingVersion,
                    $profile->name,
                ),
            );

            $stored = $this->writeFile($storage, $output, 'image/jpeg');
            $now = new DateTimeImmutable();

            return new MediaDerivative(
                Uuid::v7(),
                $media->id,
                'video',
                $profile->name,
                $processingVersion,
                $storage,
                'image/jpeg',
                $stored->byteSize,
                (int) $imageInfo[0],
                (int) $imageInfo[1],
                null,
                [
                    'generator' => 'ffmpeg',
                    'role' => 'poster',
                    'quality_scale' => $profile->qualityScale,
                ],
                $now,
                $now,
            );
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    private function generatePlayback(
        MediaAsset $media,
        VideoPlaybackProfile $profile,
        int $processingVersion,
    ): MediaDerivative {
        [$input, $output] = $this->temporaryPair('mp4');

        try {
            $this->copyOriginal($media, $input);

            $this->ffmpeg->run([
                '-y',
                '-i', $input,
                '-map', '0:v:0',
                '-map', '0:a:0?',
                '-sn',
                '-dn',
                '-map_metadata', '-1',
                '-vf', $this->scaleFilter($profile->maximumWidth, $profile->maximumHeight),
                '-c:v', $profile->videoCodec,
                '-preset', $profile->preset,
                '-crf', (string) $profile->crf,
                '-pix_fmt', 'yuv420p',
                '-c:a', $profile->audioCodec,
                '-b:a', $profile->audioBitrate,
                '-movflags', '+faststart',
                '-f', 'mp4',
                $output,
            ]);

            $properties = $this->ffprobe->videoProperties($output);

            $storage = new StorageObjectId(
                'media',
                sprintf(
                    'derivatives/%s/v%d/%s.mp4',
                    $media->id->toRfc4122(),
                    $processingVersion,
                    $profile->name,
                ),
            );

            $stored = $this->writeFile($storage, $output, 'video/mp4');
            $now = new DateTimeImmutable();

            return new MediaDerivative(
                Uuid::v7(),
                $media->id,
                'video',
                $profile->name,
                $processingVersion,
                $storage,
                'video/mp4',
                $stored->byteSize,
                $properties->width,
                $properties->height,
                $properties->durationMs,
                [
                    'generator' => 'ffmpeg',
                    'role' => 'browser_playback',
                    'video_codec' => $properties->videoCodec,
                    'audio_codec' => $properties->audioCodec,
                    'has_audio' => $properties->hasAudio,
                    'container' => $properties->container,
                    'crf' => $profile->crf,
                    'preset' => $profile->preset,
                ],
                $now,
                $now,
            );
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    private function copyOriginal(MediaAsset $media, string $targetPath): void
    {
        $source = $this->storage->read($media->original);
        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            fclose($source);
            throw new \RuntimeException('Unable to open temporary video source.');
        }

        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new \RuntimeException('Unable to copy video source for processing.');
            }
        } finally {
            fclose($target);
            fclose($source);
        }
    }

    /** @return array{0:string,1:string} */
    private function temporaryPair(string $extension): array
    {
        $input = tempnam(sys_get_temp_dir(), 'mediarama-video-in-');
        $baseOutput = tempnam(sys_get_temp_dir(), 'mediarama-video-out-');

        if ($input === false || $baseOutput === false) {
            if (is_string($input)) {
                @unlink($input);
            }
            if (is_string($baseOutput)) {
                @unlink($baseOutput);
            }

            throw new \RuntimeException('Unable to allocate video processing files.');
        }

        @unlink($baseOutput);

        return [$input, $baseOutput.'.'.$extension];
    }

    private function scaleFilter(int $maximumWidth, int $maximumHeight): string
    {
        return sprintf(
            "scale=w='min(%d,iw)':h='min(%d,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            $maximumWidth,
            $maximumHeight,
        );
    }

    private function writeFile(
        StorageObjectId $storage,
        string $path,
        string $mimeType,
    ): \Mediarama\Media\Application\StoredObject {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open generated video derivative.');
        }

        try {
            return $this->storage->write($storage, $stream, $mimeType);
        } finally {
            fclose($stream);
        }
    }
}
