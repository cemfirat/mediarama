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
     *   watermark_width_percent: int,
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

        $assetPath = $this->configuration->assetPath();
        $assetHashBefore = hash_file('sha256', $assetPath);
        if ($assetHashBefore === false) {
            throw new \RuntimeException('Unable to fingerprint configured watermark asset before rendering.');
        }

        [$width, $height] = $this->dimensions($intermediatePath);

        $watermarkWidth = max(
            1,
            (int) round($width * ($this->configuration->widthPercent() / 100)),
        );
        $margin = max(
            0,
            (int) round(min($width, $height) * ($this->configuration->marginPercent() / 100)),
        );
        $opacity = $this->configuration->opacityPercent() / 100;

        $this->process->convert([
            $intermediatePath,
            '(',
            $assetPath,
            '-alpha',
            'set',
            '-resize',
            $watermarkWidth.'x',
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

        $assetHashAfter = hash_file('sha256', $assetPath);
        if ($assetHashAfter === false || !hash_equals($assetHashBefore, $assetHashAfter)) {
            throw new \RuntimeException('Configured watermark asset changed while the derivative was being rendered.');
        }

        return [
            'watermarked' => true,
            'watermark_fingerprint' => $this->configuration->fingerprintFromAssetHash($assetHashBefore),
            'watermark_gravity' => $this->configuration->gravity(),
            'watermark_width_percent' => $this->configuration->widthPercent(),
            'watermark_opacity_percent' => $this->configuration->opacityPercent(),
            'watermark_margin_percent' => $this->configuration->marginPercent(),
        ];
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
