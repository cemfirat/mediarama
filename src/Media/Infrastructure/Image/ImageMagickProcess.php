<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Image;

use Mediarama\Media\Infrastructure\Process\MediaToolRejected;
use Mediarama\Media\Infrastructure\Process\MediaToolUnavailable;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

final readonly class ImageMagickProcess
{
    public function __construct(
        private ImageMagickResourceLimits $limits,
        private string $binary = 'magick',
        private string $identifyBinary = 'identify',
        private float $timeoutSeconds = 60.0,
    ) {
        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('ImageMagick process timeout must be positive.');
        }
    }

    /** @param list<string> $arguments */
    public function convert(array $arguments): string
    {
        return $this->run($this->binary, 'convert', $arguments);
    }

    /** @param list<string> $arguments */
    public function identify(array $arguments): string
    {
        return $this->run($this->identifyBinary, 'identify', $arguments);
    }

    /** @param list<string> $arguments */
    private function run(string $binary, string $operation, array $arguments): string
    {
        $process = new Process(
            [$binary, ...$this->limits->commandArguments(), ...$arguments],
            null,
            $this->limits->environment(),
        );
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessException $error) {
            throw new MediaToolUnavailable(
                sprintf('ImageMagick %s process could not complete.', $operation),
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
                'ImageMagick %s failed with exit code %s%s',
                $operation,
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
