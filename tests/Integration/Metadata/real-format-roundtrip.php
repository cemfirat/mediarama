<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Export\Infrastructure\ExifToolMetadataWriter;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Metadata\ExifToolMetadataParser;
use Mediarama\Media\Infrastructure\Metadata\ExifToolProcess;
use Mediarama\Media\Infrastructure\Metadata\LocalExifToolInspector;
use Symfony\Component\Process\Process;

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function runCommand(array $command, float $timeout = 30.0): void
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

/** @param resource $stream */
function writeStreamToFile($stream, string $path): void
{
    $target = fopen($path, 'wb');
    if ($target === false) {
        throw new RuntimeException('Unable to open exported metadata fixture for writing.');
    }

    try {
        if (stream_copy_to_stream($stream, $target) === false) {
            throw new RuntimeException('Unable to persist exported metadata fixture.');
        }
    } finally {
        fclose($target);
        fclose($stream);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path.'/'.$item;
        if (is_dir($child)) {
            removeTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}

function assertNear(?float $actual, float $expected, string $message): void
{
    requireCondition($actual !== null && abs($actual - $expected) < 0.0001, $message);
}

$convertBinary = getenv('IMAGEMAGICK_BINARY') ?: 'convert';
$exiftoolBinary = getenv('EXIFTOOL_BINARY') ?: 'exiftool';
$root = sys_get_temp_dir().'/mediarama-real-metadata-formats-'.getmypid();

if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Unable to create metadata integration-test directory.');
}

$formats = [
    'jpeg' => ['extension' => 'jpg', 'mime' => 'image/jpeg'],
    'tiff' => ['extension' => 'tif', 'mime' => 'image/tiff'],
    'png' => ['extension' => 'png', 'mime' => 'image/png'],
    'webp' => ['extension' => 'webp', 'mime' => 'image/webp'],
    'avif' => ['extension' => 'avif', 'mime' => 'image/avif'],
    'heic' => ['extension' => 'heic', 'mime' => 'image/heic'],
];

$process = new ExifToolProcess($exiftoolBinary, 30.0);
$parser = new ExifToolMetadataParser();
$inspector = new LocalExifToolInspector($process, $parser, $root);
$writer = new ExifToolMetadataWriter($process);

try {
    foreach ($formats as $name => $format) {
        $extension = $format['extension'];
        $mimeType = $format['mime'];
        $sourceName = 'source-'.$name.'.'.$extension;
        $sourcePath = $root.'/'.$sourceName;

        runCommand([
            $convertBinary,
            '-size',
            '64x48',
            'xc:white',
            $sourcePath,
        ], 30.0);

        requireCondition(is_file($sourcePath) && filesize($sourcePath) > 0, 'ImageMagick did not create '.$name.' fixture.');

        runCommand([
            $exiftoolBinary,
            '-overwrite_original',
            '-XMP-dc:Title=Embedded '.$name,
            '-XMP-dc:Creator=Fixture Creator',
            '-XMP-dc:Rights=Fixture Copyright',
            '-XMP-iptcCore:Location=Vienna Embedded',
            '-XMP-exif:GPSLatitude=48.2082',
            '-XMP-exif:GPSLongitude=16.3738',
            '--',
            $sourcePath,
        ], 30.0);

        $sourceHash = hash_file('sha256', $sourcePath);
        requireCondition(is_string($sourceHash), 'Unable to hash '.$name.' source fixture.');

        $original = new StorageObjectId('media', $sourceName);
        $embedded = $inspector->inspect($original);

        requireCondition($embedded->title === 'Embedded '.$name, 'Failed to extract XMP title from real '.$name.' fixture.');
        requireCondition($embedded->creator === 'Fixture Creator', 'Failed to extract XMP creator from real '.$name.' fixture.');
        requireCondition($embedded->copyright === 'Fixture Copyright', 'Failed to extract XMP copyright from real '.$name.' fixture.');
        requireCondition($embedded->locationName === 'Vienna Embedded', 'Failed to extract XMP location from real '.$name.' fixture.');
        assertNear($embedded->latitude, 48.2082, 'Failed to extract XMP latitude from real '.$name.' fixture.');
        assertNear($embedded->longitude, 16.3738, 'Failed to extract XMP longitude from real '.$name.' fixture.');

        $media = MediaAsset::create(
            ownerId: null,
            original: $original,
            originalFilename: $sourceName,
            mimeType: $mimeType,
            mediaType: MediaType::Image,
            byteSize: (int) filesize($sourcePath),
            checksumSha256: $sourceHash,
        );
        $media->title = 'Current '.$name;
        $media->description = 'Round-trip '.$name.' metadata';
        $media->creator = 'Mediarama Export';
        $media->copyright = 'Mediarama Test';
        $media->locationName = 'Vienna Export';
        $media->latitude = 48.21;
        $media->longitude = 16.37;

        $source = fopen($sourcePath, 'rb');
        requireCondition($source !== false, 'Unable to open '.$name.' source for metadata export.');
        $currentStream = $writer->write(
            $source,
            $media,
            new MetadataExportPolicy(MetadataExportProfile::Current),
        );
        fclose($source);

        $currentName = 'current-'.$name.'.'.$extension;
        $currentPath = $root.'/'.$currentName;
        writeStreamToFile($currentStream, $currentPath);

        requireCondition(hash_file('sha256', $sourcePath) === $sourceHash, 'Metadata export mutated immutable '.$name.' source.');

        $current = $inspector->inspect(new StorageObjectId('media', $currentName));
        requireCondition($current->title === 'Current '.$name, 'Current metadata title did not round-trip for '.$name.'.');
        requireCondition($current->description === 'Round-trip '.$name.' metadata', 'Current metadata description did not round-trip for '.$name.'.');
        requireCondition($current->creator === 'Mediarama Export', 'Current metadata creator did not round-trip for '.$name.'.');
        requireCondition($current->copyright === 'Mediarama Test', 'Current metadata copyright did not round-trip for '.$name.'.');
        requireCondition($current->locationName === 'Vienna Export', 'Current metadata location did not round-trip for '.$name.'.');
        assertNear($current->latitude, 48.21, 'Current metadata latitude did not round-trip for '.$name.'.');
        assertNear($current->longitude, 16.37, 'Current metadata longitude did not round-trip for '.$name.'.');

        $source = fopen($sourcePath, 'rb');
        requireCondition($source !== false, 'Unable to reopen '.$name.' source for privacy-safe export.');
        $privacyStream = $writer->write(
            $source,
            $media,
            MetadataExportPolicy::privacySafe(),
        );
        fclose($source);

        $privacyName = 'privacy-'.$name.'.'.$extension;
        $privacyPath = $root.'/'.$privacyName;
        writeStreamToFile($privacyStream, $privacyPath);

        $privacy = $inspector->inspect(new StorageObjectId('media', $privacyName));
        requireCondition($privacy->title === 'Current '.$name, 'Privacy-safe export lost current title for '.$name.'.');
        requireCondition($privacy->creator === 'Mediarama Export', 'Privacy-safe export lost current creator for '.$name.'.');
        requireCondition($privacy->latitude === null, 'Privacy-safe export retained latitude for '.$name.'.');
        requireCondition($privacy->longitude === null, 'Privacy-safe export retained longitude for '.$name.'.');

        echo 'OK '.$name.' metadata extract/current/privacy-safe round-trip'.PHP_EOL;
    }
} finally {
    removeTree($root);
}
