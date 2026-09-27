<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Image;

final readonly class ImageWatermarkConfiguration
{
    private const GRAVITIES = [
        'northwest' => 'NorthWest',
        'north' => 'North',
        'northeast' => 'NorthEast',
        'west' => 'West',
        'center' => 'Center',
        'east' => 'East',
        'southwest' => 'SouthWest',
        'south' => 'South',
        'southeast' => 'SouthEast',
    ];

    private const MAX_ASSET_BYTES = 16 * 1024 * 1024;
    private const MAX_ASSET_DIMENSION = 8192;

    public function __construct(
        private string $assetPath,
        private int $widthPercent = 18,
        private int $opacityPercent = 35,
        private int $marginPercent = 2,
        private string $gravity = 'southeast',
    ) {
        if ($widthPercent < 1 || $widthPercent > 50) {
            throw new \InvalidArgumentException('Watermark width percent must be between 1 and 50.');
        }

        if ($opacityPercent < 1 || $opacityPercent > 100) {
            throw new \InvalidArgumentException('Watermark opacity percent must be between 1 and 100.');
        }

        if ($marginPercent < 0 || $marginPercent > 20) {
            throw new \InvalidArgumentException('Watermark margin percent must be between 0 and 20.');
        }

        if (!isset(self::GRAVITIES[strtolower($gravity)])) {
            throw new \InvalidArgumentException('Unsupported watermark gravity.');
        }
    }

    public function assetPath(): string
    {
        $configured = trim($this->assetPath);
        if ($configured === '') {
            throw new \DomainException('Watermark asset path is not configured.');
        }

        $path = realpath($configured);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new \DomainException('Configured watermark asset is not a readable file.');
        }

        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_ASSET_BYTES) {
            throw new \DomainException('Configured watermark asset has an invalid file size.');
        }

        $image = getimagesize($path);
        if ($image === false
            || ($image['mime'] ?? null) !== 'image/png'
            || (int) $image[0] < 1
            || (int) $image[1] < 1
            || (int) $image[0] > self::MAX_ASSET_DIMENSION
            || (int) $image[1] > self::MAX_ASSET_DIMENSION) {
            throw new \DomainException('Configured watermark asset must be a valid bounded PNG image.');
        }

        return $path;
    }

    public function widthPercent(): int
    {
        return $this->widthPercent;
    }

    public function opacityPercent(): int
    {
        return $this->opacityPercent;
    }

    public function marginPercent(): int
    {
        return $this->marginPercent;
    }

    public function imageMagickGravity(): string
    {
        return self::GRAVITIES[strtolower($this->gravity)];
    }

    public function gravity(): string
    {
        return strtolower($this->gravity);
    }

    public function fingerprintFromAssetHash(string $assetHash): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $assetHash)) {
            throw new \InvalidArgumentException('Watermark asset hash must be a lowercase SHA-256 digest.');
        }

        return hash('sha256', implode('|', [
            $assetHash,
            (string) $this->widthPercent,
            (string) $this->opacityPercent,
            (string) $this->marginPercent,
            $this->gravity(),
        ]));
    }
}
