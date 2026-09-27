<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Image;

use Mediarama\Media\Infrastructure\Process\MediaToolRejected;
use Mediarama\Media\Infrastructure\Process\MediaToolUnavailable;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

final readonly class CwebpEncoder
{
    public function __construct(
        private string $binary = 'cwebp',
        private float $timeoutSeconds = 60.0,
    ) {
        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('cwebp process timeout must be positive.');
        }
    }

    public function encode(
        string $inputPath,
        string $outputPath,
        int $quality,
    ): void {
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new \InvalidArgumentException('cwebp input must be a readable file.');
        }

        if ($quality < 1 || $quality > 100) {
            throw new \InvalidArgumentException('cwebp quality must be between 1 and 100.');
        }

        $process = new Process([
            $this->binary,
            '-quiet',
            '-q',
            (string) $quality,
            '-m',
            '4',
            '-alpha_q',
            '100',
            '-metadata',
            'none',
            $inputPath,
            '-o',
            $outputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessException $error) {
            throw new MediaToolUnavailable(
                'cwebp process could not complete.',
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
                'cwebp failed with exit code %s%s',
                $exitCode === null ? 'unknown' : (string) $exitCode,
                $details === '' ? '.' : ': '.$details,
            );

            if ($exitCode === null || in_array($exitCode, [126, 127], true)) {
                throw new MediaToolUnavailable($message);
            }

            throw new MediaToolRejected($message);
        }

        $size = is_file($outputPath) ? filesize($outputPath) : false;
        if ($size === false || $size < 1) {
            throw new MediaToolRejected('cwebp completed without producing a non-empty WebP file.');
        }
    }

    public function version(): string
    {
        $process = new Process([$this->binary, '-version']);
        $process->setTimeout(min($this->timeoutSeconds, 15.0));

        try {
            $process->run();
        } catch (ProcessException $error) {
            throw new MediaToolUnavailable(
                'cwebp version process could not complete.',
                0,
                $error,
            );
        }

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $message = sprintf(
                'cwebp version check failed with exit code %s.',
                $exitCode === null ? 'unknown' : (string) $exitCode,
            );

            if ($exitCode === null || in_array($exitCode, [126, 127], true)) {
                throw new MediaToolUnavailable($message);
            }

            throw new MediaToolRejected($message);
        }

        $version = trim($process->getOutput());
        if ($version === '') {
            throw new MediaToolRejected('cwebp version check returned an empty response.');
        }

        return $version;
    }
}
