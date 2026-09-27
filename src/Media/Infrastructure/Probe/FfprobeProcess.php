<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Probe;

use JsonException;
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
        $process = new Process([
            $this->binary,
            '-v', 'error',
            '-probesize', (string) $this->probeSizeBytes,
            '-analyzeduration', (string) $this->analyzeDurationMicroseconds,
            '-show_entries', 'stream=codec_type',
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

        if (!is_array($decoded) || !isset($decoded['streams']) || !is_array($decoded['streams'])) {
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
}
