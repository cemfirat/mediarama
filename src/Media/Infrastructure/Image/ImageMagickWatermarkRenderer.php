<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Image;

final readonly class ImageMagickWatermarkRenderer
{
    public function __construct(
        private ImageMagickProcess $process,
        private ImageWatermarkConfiguration $configuration,
    ) {
    }

    /**
     * @return array{
     *   watermarked: true,
     *   watermark_fingerprint: string,
     *   watermark_gravity: string,
     *   watermark_size_percent: int,
     *   watermark_opacity_percent: int,
     *   watermark_margin_percent: int
     * }
     */
    public function render(
        string $intermediatePath,
        string $outputPath,
        int $quality,
    ): array {
        if (!is_file($intermediatePath)) {
            throw new \InvalidArgumentException('Watermark intermediate image does not exist.');
        }

        [$assetSnapshot, $assetHash] = $this->snapshotAsset();

        try {
            [$width, $height] = $this->dimensions($intermediatePath);

            $watermarkMaximumWidth = max(
                1,
                (int) round($width * ($this->configuration->sizePercent() / 100)),
            );
            $watermarkMaximumHeight = max(
                1,
                (int) round($height * ($this->configuration->sizePercent() / 100)),
            );
            $margin = max(
                0,
                (int) round(min($width, $height) * ($this->configuration->marginPercent() / 100)),
            );
            $opacity = $this->configuration->opacityPercent() / 100;

            $this->process->convert([
                $intermediatePath,
                '(',
                $assetSnapshot,
                '-alpha',
                'set',
                '-resize',
                sprintf('%dx%d', $watermarkMaximumWidth, $watermarkMaximumHeight),
                '-channel',
                'A',
                '-evaluate',
                'multiply',
                rtrim(rtrim(sprintf('%.4F', $opacity), '0'), '.'),
                '+channel',
                ')',
                '-gravity',
                $this->configuration->imageMagickGravity(),
                '-geometry',
                $this->configuration->geometryOffset($margin),
                '-compose',
                'Over',
                '-composite',
                '-strip',
                '-quality',
                (string) $quality,
                $outputPath,
            ]);

            return [
                'watermarked' => true,
                'watermark_fingerprint' => $this->configuration->fingerprintFromAssetHash($assetHash),
                'watermark_gravity' => $this->configuration->gravity(),
                'watermark_size_percent' => $this->configuration->sizePercent(),
                'watermark_opacity_percent' => $this->configuration->opacityPercent(),
                'watermark_margin_percent' => $this->configuration->marginPercent(),
            ];
        } finally {
            @unlink($assetSnapshot);
        }
    }

    /** @return array{0: string, 1: string} */
    private function snapshotAsset(): array
    {
        $assetPath = $this->configuration->assetPath();
        $bytes = file_get_contents($assetPath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Unable to read configured watermark asset.');
        }

        $snapshot = tempnam(sys_get_temp_dir(), 'mediarama-watermark-asset-');
        if ($snapshot === false) {
            throw new \RuntimeException('Unable to allocate watermark asset snapshot.');
        }

        try {
            $written = file_put_contents($snapshot, $bytes, LOCK_EX);
            if ($written === false || $written !== strlen($bytes)) {
                throw new \RuntimeException('Unable to persist watermark asset snapshot.');
            }
        } catch (\Throwable $error) {
            @unlink($snapshot);

            throw $error;
        }

        return [$snapshot, hash('sha256', $bytes)];
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $path): array
    {
        $raw = trim($this->process->identify([
            '-format',
            '%w %h',
            $path.'[0]',
        ]));

        if (!preg_match('/^(\d+)\s+(\d+)$/', $raw, $match)) {
            throw new \RuntimeException('Unable to determine watermark intermediate dimensions.');
        }

        $width = (int) $match[1];
        $height = (int) $match[2];

        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('Watermark intermediate dimensions are invalid.');
        }

        return [$width, $height];
    }
}
