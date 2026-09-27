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
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Symfony\Component\Process\Process;

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable(): void $operation */
function requireRuntimeFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

/** @param list<string> $arguments */
function runRawImageMagick(string $binary, array $arguments): void
{
    $process = new Process([$binary, ...$arguments]);
    $process->setTimeout(30.0);
    $process->mustRun();
}

function removeTree(string $path): void
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

$convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
$identifyBinary = trim((string) getenv('IMAGEMAGICK_IDENTIFY_BINARY'));
requireCondition($convertBinary !== '', 'IMAGEMAGICK_BINARY must be configured.');
requireCondition($identifyBinary !== '', 'IMAGEMAGICK_IDENTIFY_BINARY must be configured.');

$limits = new ImageMagickResourceLimits(
    memory: '32MiB',
    map: '64MiB',
    disk: '128MiB',
    area: '32MiB',
    width: 64,
    height: 64,
    files: 16,
    threads: 1,
    timeSeconds: 10,
    listLength: 4,
);
$runner = new ImageMagickProcess($limits, $convertBinary, $identifyBinary, 15.0);

$suffix = bin2hex(random_bytes(8));
$temporaryRoot = sys_get_temp_dir().'/mediarama-imagemagick-'.$suffix;
$storageRoot = $temporaryRoot.'/storage';
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Unable to create ImageMagick integration-test directory.');
}

$normal = $temporaryRoot.'/normal.png';
$oversized = $temporaryRoot.'/oversized.png';
$converted = $temporaryRoot.'/converted.webp';
$malformed = $temporaryRoot.'/malformed.png';
$malformedOutput = $temporaryRoot.'/malformed.webp';
$stress = $temporaryRoot.'/decode-stress.png';
$stressOutput = $temporaryRoot.'/decode-stress.webp';

try {
    $runner->convert(['-size', '32x32', 'xc:white', $normal]);
    $normalGeometry = trim($runner->identify(['-format', '%w %h', $normal.'[0]']));
    requireCondition($normalGeometry === '32 32', 'Normal image geometry verification failed: '.$normalGeometry);

    runRawImageMagick($convertBinary, ['-size', '128x1', 'xc:white', $oversized]);
    requireRuntimeFailure(
        static fn () => $runner->identify(['-format', '%w %h', $oversized.'[0]']),
        'Oversized image was not blocked by identify limits.',
    );
    requireRuntimeFailure(
        static fn () => $runner->convert([$oversized.'[0]', '-thumbnail', '32x32>', $converted]),
        'Oversized image was not blocked by conversion limits.',
    );

    $storage = new LocalMediaStorage($storageRoot);
    $originalId = new StorageObjectId('media', 'originals/oversized.png');
    $source = fopen($oversized, 'rb');
    if ($source === false) {
        throw new RuntimeException('Unable to open oversized fixture.');
    }

    try {
        $stored = $storage->write($originalId, $source, 'image/png');
    } finally {
        fclose($source);
    }

    requireCondition($stored->checksum !== null, 'Oversized fixture checksum was not created.');
    $asset = MediaAsset::create(
        null,
        $originalId,
        'oversized.png',
        'image/png',
        MediaType::Image,
        $stored->byteSize,
        $stored->checksum,
    );
    $profile = new ImageDerivativeProfile('resource-test', 32, 32, 'webp', 82, false);
    $generator = new ImageMagickDerivativeGenerator(
        $storage,
        $runner,
        new ImageMagickWatermarkRenderer($runner, new ImageWatermarkConfiguration('')),
    );
    requireRuntimeFailure(
        static fn () => $generator->generate($asset, $profile, 1),
        'Oversized image unexpectedly produced a derivative.',
    );
    $derivativeId = new StorageObjectId(
        'media',
        sprintf('derivatives/%s/v1/resource-test.webp', $asset->id->toRfc4122()),
    );
    requireCondition(!$storage->exists($derivativeId), 'Failed conversion persisted a partial derivative.');

    $normalBytes = file_get_contents($normal);
    if ($normalBytes === false || strlen($normalBytes) < 64) {
        throw new RuntimeException('Normal image fixture is unexpectedly small.');
    }
    $malformedBytes = substr($normalBytes, 0, intdiv(strlen($normalBytes), 2));
    requireCondition(
        file_put_contents($malformed, $malformedBytes) !== false,
        'Unable to create truncated image fixture.',
    );
    requireRuntimeFailure(
        static fn () => $runner->convert([$malformed.'[0]', '-thumbnail', '16x16>', $malformedOutput]),
        'Truncated image was not rejected by the decoder path.',
    );

    runRawImageMagick($convertBinary, ['-size', '2048x2048', 'xc:white', $stress]);
    $compressedBytes = filesize($stress);
    requireCondition(
        $compressedBytes !== false && $compressedBytes > 0,
        'Decode-stress fixture was not created.',
    );
    requireCondition(
        $compressedBytes < 1024 * 1024,
        'Decode-stress fixture is not sufficiently compressed for this test.',
    );

    $stressRunner = new ImageMagickProcess(
        new ImageMagickResourceLimits(
            memory: '1MiB',
            map: '1MiB',
            disk: '1MiB',
            area: '1MiB',
            width: 4096,
            height: 4096,
            files: 16,
            threads: 1,
            timeSeconds: 10,
            listLength: 4,
        ),
        $convertBinary,
        $identifyBinary,
        15.0,
    );
    requireRuntimeFailure(
        static fn () => $stressRunner->convert([$stress.'[0]', '-thumbnail', '64x64>', $stressOutput]),
        'Highly compressed decode-stress image was not blocked by cache limits.',
    );
    requireCondition(!is_file($stressOutput), 'Decode-stress failure left a generated output file.');

    echo "OK normal image processing\n";
    echo "OK oversized dimension rejection\n";
    echo "OK failed derivative is not persisted\n";
    echo "OK truncated image rejection\n";
    echo "OK compressed decode-stress rejection\n";
} finally {
    removeTree($temporaryRoot);
}
