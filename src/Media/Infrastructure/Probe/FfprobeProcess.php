<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Probe;

use JsonException;
use Mediarama\Media\Application\VideoProperties;
use Mediarama\Media\Infrastructure\Process\MediaToolRejected;
use Mediarama\Media\Infrastructure\Process\MediaToolUnavailable;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

final readonly class FfprobeProcess
{
    public function __construct(
        private string $binary = 'ffprobe',
        private float $timeoutSeconds = 30.0,
        private int $probeSizeBytes = 33554432,
        private int $analyzeDurationMicroseconds = 5000000,
    ) {
        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('FFprobe process timeout must be positive.');
        }
        if ($this->probeSizeBytes < 32) {
            throw new \InvalidArgumentException('FFprobe probe size must be at least 32 bytes.');
        }
        if ($this->analyzeDurationMicroseconds < 0) {
            throw new \InvalidArgumentException('FFprobe analyze duration must not be negative.');
        }
    }

    /** @return list<string> */
    public function streamTypes(string $path): array
    {
        $decoded = $this->probe(
            'stream=codec_type',
            $path,
        );

        if (!isset($decoded['streams']) || !is_array($decoded['streams'])) {
            throw new MediaToolUnavailable('FFprobe response does not contain a streams array.');
        }

        $types = [];
        foreach ($decoded['streams'] as $stream) {
            if (!is_array($stream)) {
                continue;
            }

            $type = $stream['codec_type'] ?? null;
            if (is_string($type) && $type !== '') {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    public function videoProperties(string $path): VideoProperties
    {
        $decoded = $this->probe(
            'format=duration,format_name:stream=codec_type,codec_name,width,height,duration:stream_tags=rotate:stream_side_data=rotation',
            $path,
        );

        if (!isset($decoded['streams']) || !is_array($decoded['streams'])) {
            throw new MediaToolUnavailable('FFprobe response does not contain a streams array.');
        }

        $video = null;
        $audio = null;

        foreach ($decoded['streams'] as $stream) {
            if (!is_array($stream)) {
                continue;
            }

            if (($stream['codec_type'] ?? null) === 'video' && $video === null) {
                $video = $stream;
            }

            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        if ($video === null) {
            throw new MediaToolRejected('FFprobe did not report a video stream.');
        }

        $width = isset($video['width']) ? (int) $video['width'] : 0;
        $height = isset($video['height']) ? (int) $video['height'] : 0;
        $videoCodec = trim((string) ($video['codec_name'] ?? ''));

        if ($width < 1 || $height < 1 || $videoCodec === '') {
            throw new MediaToolRejected('FFprobe returned incomplete video stream properties.');
        }

        $rotation = $this->rotation($video);
        if (in_array($rotation, [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }

        $durationSeconds = $this->positiveFloat($decoded['format']['duration'] ?? null)
            ?? $this->positiveFloat($video['duration'] ?? null);
        $durationMs = $durationSeconds !== null
            ? (int) round($durationSeconds * 1000)
            : null;

        $audioCodec = null;
        if ($audio !== null) {
            $candidate = trim((string) ($audio['codec_name'] ?? ''));
            $audioCodec = $candidate !== '' ? $candidate : null;
        }

        $formatName = null;
        if (isset($decoded['format']) && is_array($decoded['format'])) {
            $candidate = trim((string) ($decoded['format']['format_name'] ?? ''));
            $formatName = $candidate !== '' ? $candidate : null;
        }

        return new VideoProperties(
            $width,
            $height,
            $durationMs,
            $audio !== null,
            $videoCodec,
            $audioCodec,
            $formatName,
        );
    }

    /** @return array<string,mixed> */
    private function probe(string $showEntries, string $path): array
    {
        $process = new Process([
            $this->binary,
            '-v', 'error',
            '-probesize', (string) $this->probeSizeBytes,
            '-analyzeduration', (string) $this->analyzeDurationMicroseconds,
            '-show_entries', $showEntries,
            '-of', 'json=c=1',
            $path,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessException $error) {
            throw new MediaToolUnavailable(
                'FFprobe process could not complete.',
                0,
                $error,
            );
        }

        if (!$process->isSuccessful()) {
            $details = trim($process->getErrorOutput());
            if ($details === '') {
                $details = trim($process->getOutput());
            }
            $details = $details === '' ? '' : substr($details, 0, 2000);
            $exitCode = $process->getExitCode();

            $message = sprintf(
                'FFprobe failed with exit code %s%s',
                $exitCode === null ? 'unknown' : (string) $exitCode,
                $details === '' ? '.' : ': '.$details,
            );

            if ($exitCode === null || in_array($exitCode, [126, 127], true)) {
                throw new MediaToolUnavailable($message);
            }

            throw new MediaToolRejected($message);
        }

        try {
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new MediaToolUnavailable('FFprobe returned invalid JSON.', 0, $error);
        }

        if (!is_array($decoded)) {
            throw new MediaToolUnavailable('FFprobe response is not a JSON object.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $stream */
    private function rotation(array $stream): int
    {
        $rotation = null;

        if (isset($stream['side_data_list']) && is_array($stream['side_data_list'])) {
            foreach ($stream['side_data_list'] as $sideData) {
                if (!is_array($sideData) || !isset($sideData['rotation'])) {
                    continue;
                }

                if (is_numeric($sideData['rotation'])) {
                    $rotation = (int) round((float) $sideData['rotation']);
                    break;
                }
            }
        }

        if (
            $rotation === null
            && isset($stream['tags'])
            && is_array($stream['tags'])
            && isset($stream['tags']['rotate'])
            && is_numeric($stream['tags']['rotate'])
        ) {
            $rotation = (int) round((float) $stream['tags']['rotate']);
        }

        if ($rotation === null) {
            return 0;
        }

        $normalized = (($rotation % 360) + 360) % 360;

        return match (true) {
            $normalized >= 45 && $normalized < 135 => 90,
            $normalized >= 135 && $normalized < 225 => 180,
            $normalized >= 225 && $normalized < 315 => 270,
            default => 0,
        };
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }
}
