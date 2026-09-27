<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Image;

use Mediarama\Media\Domain\MediaToolRejected;
use Mediarama\Media\Domain\MediaToolUnavailable;
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

            $message = sprintf(
                'ImageMagick %s rejected the input with exit code %s%s',
                $operation,
                (string) $process->getExitCode(),
                $details === '' ? '.' : ': '.$details,
            );

            if (in_array($process->getExitCode(), [126, 127], true)) {
                throw new MediaToolUnavailable($message);
            }

            throw new MediaToolRejected($message);
        }

        return $process->getOutput();
    }
}
