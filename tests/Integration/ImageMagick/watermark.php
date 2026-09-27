<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Mediarama\Media\Application\ImageDerivativeProfile;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Image\ImageMagickDerivativeGenerator;
use Mediarama\Media\Infrastructure\Image\ImageMagickProcess;
use Mediarama\Media\Infrastructure\Image\ImageMagickResourceLimits;
use Mediarama\Media\Infrastructure\Image\ImageMagickWatermarkRenderer;
use Mediarama\Media\Infrastructure\Image\ImageWatermarkConfiguration;
use Mediarama\Media\Infrastructure\Metadata\ExifToolProcess;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Symfony\Component\Process\Process;

function requireWatermark(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

/** @param list<string> $command */
function runWatermarkCommand(array $command): void
{
    $process = new Process($command);
    $process->setTimeout(30.0);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            "Command failed (%s): %s\n%s",
            implode(' ', $command),
            trim($process->getErrorOutput()),
            trim($process->getOutput()),
        ));
    }
}

function removeWatermarkTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

function regionMean(ImageMagickProcess $process, string $path, string $geometry): float
{
    $raw = trim($process->convert([
        $path,
        '-crop',
        $geometry,
        '+repage',
        '-colorspace',
        'Gray',
        '-format',
        '%[fx:mean]',
        'info:',
    ]));

    if ($raw === '' || !is_numeric($raw)) {
        throw new RuntimeException('Unable to read watermark verification region mean: '.$raw);
    }

    return (float) $raw;
}

$convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
$identifyBinary = trim((string) getenv('IMAGEMAGICK_IDENTIFY_BINARY'));
$exiftoolBinary = trim((string) getenv('EXIFTOOL_BINARY'));

requireWatermark($convertBinary !== '', 'IMAGEMAGICK_BINARY is configured');
requireWatermark($identifyBinary !== '', 'IMAGEMAGICK_IDENTIFY_BINARY is configured');
requireWatermark($exiftoolBinary !== '', 'EXIFTOOL_BINARY is configured');

$root = sys_get_temp_dir().'/mediarama-watermark-'.bin2hex(random_bytes(8));
$storageRoot = $root.'/storage';

if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Unable to create watermark integration directory.');
}

$sourcePath = $root.'/source.jpg';
$watermarkPath = $root.'/watermark.png';
$invalidWatermarkPath = $root.'/not-a-png.jpg';

$limits = new ImageMagickResourceLimits(
    memory: '128MiB',
    map: '256MiB',
    disk: '512MiB',
    area: '128MiB',
    width: 4096,
    height: 4096,
    files: 32,
    threads: 2,
    timeSeconds: 30,
    listLength: 8,
);
$imageMagick = new ImageMagickProcess(
    $limits,
    $convertBinary,
    $identifyBinary,
    45.0,
);
$exiftool = new ExifToolProcess($exiftoolBinary, 30.0);

try {
    runWatermarkCommand([
        $convertBinary,
        '-size',
        '400x200',
        'xc:black',
        $sourcePath,
    ]);
    runWatermarkCommand([
        $exiftoolBinary,
        '-overwrite_original',
        '-Orientation#=1',
        '-XMP-exif:GPSLatitude=48.2082',
        '-XMP-exif:GPSLongitude=16.3738',
        '--',
        $sourcePath,
    ]);

    runWatermarkCommand([
        $convertBinary,
        '-size',
        '100x50',
        'xc:none',
        '-fill',
        'white',
        '-draw',
        'rectangle 50,0 99,49',
        $watermarkPath,
    ]);
    runWatermarkCommand([
        $convertBinary,
        '-size',
        '100x50',
        'xc:white',
        $invalidWatermarkPath,
    ]);

    $storage = new LocalMediaStorage($storageRoot);
    $sourceId = new StorageObjectId('media', 'originals/watermark/source.jpg');
    $source = fopen($sourcePath, 'rb');
    if ($source === false) {
        throw new RuntimeException('Unable to open watermark source fixture.');
    }

    try {
        $stored = $storage->write($sourceId, $source, 'image/jpeg');
    } finally {
        fclose($source);
    }

    requireWatermark($stored->checksum !== null, 'source fixture checksum is available');

    $asset = MediaAsset::create(
        ownerId: null,
        original: $sourceId,
        originalFilename: 'source.jpg',
        mimeType: 'image/jpeg',
        mediaType: MediaType::Image,
        byteSize: $stored->byteSize,
        checksumSha256: $stored->checksum,
    );

    $before = $storage->stat($asset->original);

    $configuration = new ImageWatermarkConfiguration(
        assetPath: $watermarkPath,
        sizePercent: 25,
        opacityPercent: 50,
        marginPercent: 10,
        gravity: 'southeast',
    );
    $renderer = new ImageMagickWatermarkRenderer($imageMagick, $configuration);
    $generator = new ImageMagickDerivativeGenerator(
        $storage,
        $imageMagick,
        $renderer,
    );
    $profile = new ImageDerivativeProfile(
        'watermark-fixture',
        200,
        200,
        'webp',
        90,
        true,
    );

    $derivative = $generator->generate($asset, $profile, 1);

    requireWatermark($derivative->width === 200, 'watermarked derivative width matches resized source');
    requireWatermark($derivative->height === 100, 'watermarked derivative height matches resized source');
    requireWatermark(($derivative->metadata['orientation_normalized'] ?? false) === true, 'orientation-normalized metadata is retained');
    requireWatermark(($derivative->metadata['watermarked'] ?? false) === true, 'derivative is marked watermarked');
    requireWatermark(($derivative->metadata['watermark_gravity'] ?? null) === 'southeast', 'watermark gravity is recorded');
    requireWatermark(($derivative->metadata['watermark_size_percent'] ?? null) === 25, 'watermark size policy is recorded');
    requireWatermark(($derivative->metadata['watermark_opacity_percent'] ?? null) === 50, 'watermark opacity policy is recorded');
    requireWatermark(($derivative->metadata['watermark_margin_percent'] ?? null) === 10, 'watermark margin policy is recorded');
    requireWatermark(
        !array_key_exists('watermark_asset_path', $derivative->metadata)
        && !in_array($watermarkPath, $derivative->metadata, true),
        'watermark filesystem path is not persisted in derivative metadata',
    );
    requireWatermark(
        is_string($derivative->metadata['watermark_fingerprint'] ?? null)
        && strlen((string) $derivative->metadata['watermark_fingerprint']) === 64,
        'watermark configuration/asset fingerprint is recorded without exposing its path',
    );

    $outputPath = $storage->localPath($derivative->storage);
    requireWatermark(is_file($outputPath), 'watermarked derivative is persisted');

    // 400x200 -> 200x100. Watermark width is 25% => 50 px, preserving 2:1
    // aspect ratio => 25 px high. 10% margin of the shorter side => 10 px.
    // SouthEast therefore places it at x=140..189, y=65..89.
    $transparentHalfMean = regionMean($imageMagick, $outputPath, '10x10+145+70');
    $visibleHalfMean = regionMean($imageMagick, $outputPath, '10x10+175+70');
    $outsideMean = regionMean($imageMagick, $outputPath, '10x10+20+20');

    requireWatermark($outsideMean < 0.08, 'background outside watermark remains dark');
    requireWatermark($transparentHalfMean < 0.12, 'transparent half of PNG watermark preserves background');
    requireWatermark($visibleHalfMean > 0.25 && $visibleHalfMean < 0.75, '50% opacity watermark visibly blends with background');

    $after = $storage->stat($asset->original);
    requireWatermark($before->checksum === $after->checksum, 'watermark rendering leaves immutable source unchanged');

    $metadataJson = $exiftool->run([
        '-json',
        '-G1',
        '-n',
        '-Orientation',
        '-GPS:all',
        '-XMP-exif:GPSLatitude',
        '-XMP-exif:GPSLongitude',
        '--',
        $outputPath,
    ]);
    $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);
    $row = is_array($metadata) && isset($metadata[0]) && is_array($metadata[0]) ? $metadata[0] : [];

    foreach (array_keys($row) as $tag) {
        requireWatermark(
            !str_contains((string) $tag, 'GPS') && !str_contains((string) $tag, 'Orientation'),
            'watermarked public derivative does not inherit source Orientation/GPS metadata',
        );
    }

    $missingConfigurationGenerator = new ImageMagickDerivativeGenerator(
        $storage,
        $imageMagick,
        new ImageMagickWatermarkRenderer(
            $imageMagick,
            new ImageWatermarkConfiguration(''),
        ),
    );

    try {
        $missingConfigurationGenerator->generate(
            $asset,
            new ImageDerivativeProfile('missing-watermark', 200, 200, 'webp', 90, true),
            2,
        );
        throw new RuntimeException('Missing watermark configuration unexpectedly produced a derivative.');
    } catch (DomainException $error) {
        requireWatermark(
            str_contains($error->getMessage(), 'not configured'),
            'watermark-enabled profile fails closed when the asset path is missing',
        );
    }

    requireWatermark(
        !$storage->exists(new StorageObjectId(
            'media',
            sprintf('derivatives/%s/v2/missing-watermark.webp', $asset->id->toRfc4122()),
        )),
        'missing watermark configuration cannot persist a derivative',
    );

    $invalidConfiguration = new ImageWatermarkConfiguration($invalidWatermarkPath);
    try {
        $invalidConfiguration->assetPath();
        throw new RuntimeException('Non-PNG watermark asset unexpectedly passed validation.');
    } catch (DomainException $error) {
        requireWatermark(
            str_contains($error->getMessage(), 'PNG'),
            'non-PNG watermark asset is rejected',
        );
    }

    echo "Watermark rendering integration checks passed.".PHP_EOL;
} finally {
    removeWatermarkTree($root);
}
