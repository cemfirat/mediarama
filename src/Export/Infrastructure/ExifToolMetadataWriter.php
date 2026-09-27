<?php

declare(strict_types=1);

namespace Mediarama\Export\Infrastructure;

use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Application\MetadataWriter;
use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Infrastructure\Metadata\ExifToolProcess;

final readonly class ExifToolMetadataWriter implements MetadataWriter
{
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/tiff',
        'image/png',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/heif',
    ];

    public function __construct(
        private ExifToolProcess $process,
        private ExifToolMetadataArguments $arguments,
        private ExifToolPrivacySafeCopyArguments $privacySafeCopyArguments,
    ) {
    }

    public function supports(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::SUPPORTED_MIME_TYPES, true);
    }

    public function write($source, MediaAsset $media, MetadataExportPolicy $policy)
    {
        if (!is_resource($source)) {
            throw new \InvalidArgumentException('Metadata source must be a readable stream.');
        }

        $extension = $this->extensionFor($media->mimeType);
        $input = tempnam(sys_get_temp_dir(), 'mediarama-meta-in-');
        $output = tempnam(sys_get_temp_dir(), 'mediarama-meta-out-');

        if ($input === false || $output === false) {
            throw new \RuntimeException('Unable to allocate metadata export temporary files.');
        }

        $inputWithExtension = $input.'.'.$extension;
        $outputWithExtension = $output.'.'.$extension;

        try {
            rename($input, $inputWithExtension);
            rename($output, $outputWithExtension);

            $target = fopen($inputWithExtension, 'wb');
            if ($target === false) {
                throw new \RuntimeException('Unable to open metadata export input.');
            }
            stream_copy_to_stream($source, $target);
            fclose($target);

            copy($inputWithExtension, $outputWithExtension);

            $arguments = [
                '-overwrite_original',
                ...($policy->profile === MetadataExportProfile::PrivacySafe
                    ? $this->privacySafeCopyArguments->build()
                    : []),
                ...$this->arguments->build($media, $policy),
                '--',
                $outputWithExtension,
            ];

            $this->process->run($arguments);

            $result = fopen($outputWithExtension, 'rb');
            if ($result === false) {
                throw new \RuntimeException('Unable to open generated metadata export.');
            }

            // Keep the temporary file alive until the returned stream is closed by
            // copying it into an anonymous temporary stream.
            $stream = fopen('php://temp', 'w+b');
            stream_copy_to_stream($result, $stream);
            fclose($result);
            rewind($stream);

            return $stream;
        } finally {
            @unlink($input);
            @unlink($output);
            @unlink($inputWithExtension);
            @unlink($outputWithExtension);
        }
    }

    private function extensionFor(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/tiff' => 'tif',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/heic', 'image/heif' => 'heic',
            default => throw new \DomainException('Unsupported metadata export MIME type.'),
        };
    }
}
