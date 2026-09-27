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
use Mediarama\Media\Infrastructure\Metadata\ExifToolProcess;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Symfony\Component\Process\Process;

function requireFormatCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function runFormatCommand(array $command, float $timeout = 30.0): void
{
    $process = new Process($command);
    $process->setTimeout($timeout);
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

function removeFormatTree(string $path): void
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

function createFormatFixture(
    string $name,
    string $path,
    string $root,
    string $convertBinary,
): void {
    if (!in_array($name, ['avif', 'heic', 'heif'], true)) {
        runFormatCommand([
            $convertBinary,
            '-size',
            '120x80',
            'xc:white',
            $path,
        ]);

        return;
    }

    $png = $root.'/heif-source-'.$name.'.png';
    runFormatCommand([
        $convertBinary,
        '-size',
        '120x80',
        'xc:white',
        $png,
    ]);

    $command = ['heif-enc', $png];
    if ($name === 'avif') {
        $command[] = '-A';
    }
    $command[] = '-o';
    $command[] = $path;

    runFormatCommand($command);
    @unlink($png);
}

function storeFormatFixture(
    LocalMediaStorage $storage,
    string $path,
    StorageObjectId $id,
    string $mimeType,
): MediaAsset {
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open image fixture '.$path.'.');
    }

    try {
        $stored = $storage->write($id, $stream, $mimeType);
    } finally {
        fclose($stream);
    }

    requireFormatCondition($stored->checksum !== null, 'Fixture checksum was not created.');

    return MediaAsset::create(
        ownerId: null,
        original: $id,
        originalFilename: basename($path),
        mimeType: $mimeType,
        mediaType: MediaType::Image,
        byteSize: $stored->byteSize,
        checksumSha256: $stored->checksum,
    );
}

$convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
$identifyBinary = trim((string) getenv('IMAGEMAGICK_IDENTIFY_BINARY'));
$exiftoolBinary = trim((string) getenv('EXIFTOOL_BINARY'));

requireFormatCondition($convertBinary !== '', 'IMAGEMAGICK_BINARY must be configured.');
requireFormatCondition($identifyBinary !== '', 'IMAGEMAGICK_IDENTIFY_BINARY must be configured.');
requireFormatCondition($exiftoolBinary !== '', 'EXIFTOOL_BINARY must be configured.');

$root = sys_get_temp_dir().'/mediarama-real-image-formats-'.bin2hex(random_bytes(8));
$fixtureRoot = $root.'/fixtures';
$storageRoot = $root.'/storage';

if (!mkdir($fixtureRoot, 0700, true) && !is_dir($fixtureRoot)) {
    throw new RuntimeException('Unable to create real-format derivative fixture directory.');
}

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
$process = new ImageMagickProcess(
    $limits,
    $convertBinary,
    $identifyBinary,
    45.0,
);
$storage = new LocalMediaStorage($storageRoot);
$generator = new ImageMagickDerivativeGenerator($storage, $process);
$profile = new ImageDerivativeProfile(
    'real-format-fixture',
    64,
    64,
    'webp',
    82,
    false,
);

$formats = [
    'jpeg' => ['extension' => 'jpg', 'mime' => 'image/jpeg'],
    'png' => ['extension' => 'png', 'mime' => 'image/png'],
    'gif' => ['extension' => 'gif', 'mime' => 'image/gif'],
    'tiff' => ['extension' => 'tif', 'mime' => 'image/tiff'],
    'webp' => ['extension' => 'webp', 'mime' => 'image/webp'],
    'avif' => ['extension' => 'avif', 'mime' => 'image/avif'],
    'heic' => ['extension' => 'heic', 'mime' => 'image/heic'],
    'heif' => ['extension' => 'heif', 'mime' => 'image/heif'],
];

try {
    foreach ($formats as $name => $format) {
        $fixture = $fixtureRoot.'/source-'.$name.'.'.$format['extension'];
        createFormatFixture($name, $fixture, $fixtureRoot, $convertBinary);

        requireFormatCondition(
            is_file($fixture) && filesize($fixture) > 0,
            'Runtime fixture encoder did not create '.$name.' source.',
        );

        $asset = storeFormatFixture(
            $storage,
            $fixture,
            new StorageObjectId('media', 'originals/'.$name.'/source.'.$format['extension']),
            $format['mime'],
        );
        $before = $storage->stat($asset->original);

        $derivative = $generator->generate($asset, $profile, 1);

        requireFormatCondition($derivative->mimeType === 'image/webp', $name.' derivative MIME is not image/webp.');
        requireFormatCondition($derivative->width !== null && $derivative->width > 0 && $derivative->width <= 64, $name.' derivative width is invalid.');
        requireFormatCondition($derivative->height !== null && $derivative->height > 0 && $derivative->height <= 64, $name.' derivative height is invalid.');
        requireFormatCondition($derivative->byteSize > 0, $name.' derivative is empty.');
        requireFormatCondition($derivative->metadata['orientation_normalized'] ?? false, $name.' derivative is not marked orientation-normalized.');
        requireFormatCondition(($derivative->metadata['watermarked'] ?? true) === false, $name.' derivative was unexpectedly marked watermarked.');
        requireFormatCondition($storage->exists($derivative->storage), $name.' derivative was not persisted.');

        $after = $storage->stat($asset->original);
        requireFormatCondition($before->checksum === $after->checksum, $name.' processing mutated the immutable source.');

        $outputPath = $storage->localPath($derivative->storage);
        $imageInfo = getimagesize($outputPath);
        requireFormatCondition($imageInfo !== false, $name.' persisted derivative is not a readable image.');
        requireFormatCondition(($imageInfo['mime'] ?? null) === 'image/webp', $name.' persisted derivative is not WebP.');

        echo 'OK '.$name.' -> WebP derivative'.PHP_EOL;
    }

    $orientedFixture = $fixtureRoot.'/orientation-6.jpg';
    runFormatCommand([
        $convertBinary,
        '-size',
        '80x40',
        'xc:white',
        $orientedFixture,
    ]);
    runFormatCommand([
        $exiftoolBinary,
        '-overwrite_original',
        '-Orientation#=6',
        '-XMP-exif:GPSLatitude=48.2082',
        '-XMP-exif:GPSLongitude=16.3738',
        '--',
        $orientedFixture,
    ]);

    $orientedAsset = storeFormatFixture(
        $storage,
        $orientedFixture,
        new StorageObjectId('media', 'originals/orientation/source.jpg'),
        'image/jpeg',
    );
    $orientedBefore = $storage->stat($orientedAsset->original);
    $orientationProfile = new ImageDerivativeProfile(
        'orientation-fixture',
        100,
        100,
        'webp',
        82,
        false,
    );

    $orientedDerivative = $generator->generate($orientedAsset, $orientationProfile, 1);

    requireFormatCondition(
        $orientedDerivative->width === 40 && $orientedDerivative->height === 80,
        sprintf(
            'EXIF orientation was not physically normalized; got %sx%s.',
            (string) $orientedDerivative->width,
            (string) $orientedDerivative->height,
        ),
    );

    $orientedAfter = $storage->stat($orientedAsset->original);
    requireFormatCondition(
        $orientedBefore->checksum === $orientedAfter->checksum,
        'Orientation normalization mutated the immutable JPEG source.',
    );

    $exiftool = new ExifToolProcess($exiftoolBinary, 30.0);
    $metadataJson = $exiftool->run([
        '-json',
        '-G1',
        '-n',
        '-Orientation',
        '-GPS:all',
        '-XMP-exif:GPSLatitude',
        '-XMP-exif:GPSLongitude',
        '--',
        $storage->localPath($orientedDerivative->storage),
    ]);
    $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);
    $row = is_array($metadata) && isset($metadata[0]) && is_array($metadata[0]) ? $metadata[0] : [];

    foreach (array_keys($row) as $tag) {
        requireFormatCondition(
            !str_contains((string) $tag, 'GPS') && !str_contains((string) $tag, 'Orientation'),
            'Public derivative retained stripped source metadata tag '.$tag.'.',
        );
    }

    echo "OK EXIF orientation physically normalized without source mutation\n";
    echo "OK display derivative strips orientation and GPS metadata\n";
} finally {
    removeFormatTree($root);
}
