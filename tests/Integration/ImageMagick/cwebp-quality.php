<?php

declare(strict_types=1);

use Mediarama\Media\Infrastructure\Image\CwebpEncoder;
use Mediarama\Media\Infrastructure\Image\ImageMagickProcess;
use Mediarama\Media\Infrastructure\Image\ImageMagickResourceLimits;
use Mediarama\Media\Infrastructure\Process\MediaToolUnavailable;

require dirname(__DIR__, 3).'/vendor/autoload.php';

function requireCwebpQuality(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

function removeCwebpQualityTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

$cwebpBinary = trim((string) getenv('CWEBP_BINARY'));
$convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
$identifyBinary = trim((string) getenv('IMAGEMAGICK_IDENTIFY_BINARY'));

requireCwebpQuality($cwebpBinary !== '', 'CWEBP_BINARY is configured');
requireCwebpQuality($convertBinary !== '', 'IMAGEMAGICK_BINARY is configured');
requireCwebpQuality($identifyBinary !== '', 'IMAGEMAGICK_IDENTIFY_BINARY is configured');

$root = sys_get_temp_dir().'/mediarama-cwebp-quality-'.bin2hex(random_bytes(8));
if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Unable to create cwebp quality integration-test directory.');
}

$ppm = $root.'/reference.ppm';
$png = $root.'/reference.png';
$low = $root.'/quality-35.webp';
$high = $root.'/quality-90.webp';

try {
    $width = 384;
    $height = 384;
    $pixels = '';

    for ($y = 0; $y < $height; ++$y) {
        for ($x = 0; $x < $width; ++$x) {
            $checker = ((int) floor($x / 16) + (int) floor($y / 16)) % 2;
            $r = ($x * 5 + $y * 3 + (($x ^ $y) * 7) + ($checker * 53)) & 0xff;
            $g = ($x * 2 + $y * 9 + (($x * $y) % 97) + ($checker * 29)) & 0xff;
            $b = ($x * 11 + $y * 4 + (($x + $y) % 61) * 3 + ($checker * 71)) & 0xff;
            $pixels .= chr($r).chr($g).chr($b);
        }
    }

    $ppmBytes = sprintf("P6\n%d %d\n255\n", $width, $height).$pixels;
    if (file_put_contents($ppm, $ppmBytes, LOCK_EX) !== strlen($ppmBytes)) {
        throw new RuntimeException('Unable to write deterministic cwebp reference fixture.');
    }

    $imageMagick = new ImageMagickProcess(
        new ImageMagickResourceLimits(),
        $convertBinary,
        $identifyBinary,
        30.0,
    );
    $imageMagick->convert([
        $ppm,
        '-strip',
        'png:'.$png,
    ]);

    $referenceHash = hash_file('sha256', $png);
    if ($referenceHash === false) {
        throw new RuntimeException('Unable to checksum cwebp reference PNG.');
    }

    $timeout = (float) (getenv('CWEBP_PROCESS_TIMEOUT_SECONDS') ?: 60);
    $encoder = new CwebpEncoder($cwebpBinary, $timeout);

    $version = $encoder->version();
    requireCwebpQuality(
        preg_match('/^\d+\.\d+\.\d+/', $version) === 1,
        'cwebp runtime version is readable',
    );

    $encoder->encode($png, $low, 35);
    $encoder->encode($png, $high, 90);

    $lowInfo = getimagesize($low);
    $highInfo = getimagesize($high);
    requireCwebpQuality(
        is_array($lowInfo) && ($lowInfo['mime'] ?? null) === 'image/webp',
        'low-quality cwebp output is a readable WebP image',
    );
    requireCwebpQuality(
        is_array($highInfo) && ($highInfo['mime'] ?? null) === 'image/webp',
        'high-quality cwebp output is a readable WebP image',
    );

    $lowHash = hash_file('sha256', $low);
    $highHash = hash_file('sha256', $high);
    requireCwebpQuality(
        is_string($lowHash) && is_string($highHash) && !hash_equals($lowHash, $highHash),
        'different cwebp quality settings produce different encoded bytes',
    );

    $lowSize = filesize($low);
    $highSize = filesize($high);
    requireCwebpQuality(
        is_int($lowSize)
        && is_int($highSize)
        && $lowSize > 0
        && $highSize > (int) floor($lowSize * 1.10),
        'higher cwebp quality produces a materially larger deterministic fixture',
    );

    requireCwebpQuality(
        hash_file('sha256', $png) === $referenceHash,
        'cwebp encoding leaves the prepared source immutable',
    );

    $missingOutput = $root.'/missing-cwebp-output.webp';
    $missingEncoder = new CwebpEncoder($root.'/missing-cwebp-binary', 5.0);
    $missingFailedClosed = false;

    try {
        $missingEncoder->encode($png, $missingOutput, 75);
    } catch (MediaToolUnavailable) {
        $missingFailedClosed = true;
    }

    requireCwebpQuality(
        $missingFailedClosed,
        'missing cwebp runtime fails closed as unavailable',
    );
    requireCwebpQuality(
        !is_file($missingOutput),
        'missing cwebp runtime does not leave a derivative artifact',
    );
} finally {
    removeCwebpQualityTree($root);
}
