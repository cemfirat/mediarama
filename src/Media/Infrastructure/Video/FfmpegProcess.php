<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Video;

use Mediarama\Media\Infrastructure\Process\MediaToolRejected;
use Mediarama\Media\Infrastructure\Process\MediaToolUnavailable;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

final readonly class FfmpegProcess
{
    public function __construct(
        private string $binary = 'ffmpeg',
        private float $timeoutSeconds = 900.0,
    ) {
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('FFmpeg process timeout must be positive.');
        }
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): string
    {
        $process = new Process([
            $this->binary,
            '-hide_banner',
            '-loglevel', 'error',
            '-nostdin',
            ...$arguments,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessException $error) {
            throw new MediaToolUnavailable(
                'FFmpeg process could not complete.',
                0,
                $error,
            );
        }

        if (!$process->isSuccessful()) {
            $details = trim($process->getErrorOutput());
            if ($details === '') {
                $details = trim($process->getOutput());
            }
            $details = $details === '' ? '' : substr($details, 0, 4000);
            $exitCode = $process->getExitCode();

            $message = sprintf(
                'FFmpeg failed with exit code %s%s',
                $exitCode === null ? 'unknown' : (string) $exitCode,
                $details === '' ? '.' : ': '.$details,
            );

            if ($exitCode === null || in_array($exitCode, [126, 127], true)) {
                throw new MediaToolUnavailable($message);
            }

            throw new MediaToolRejected($message);
        }

        return $process->getOutput();
    }
}
