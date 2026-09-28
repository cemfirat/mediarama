<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Export\Infrastructure\ExifToolMetadataArguments;
use Mediarama\Export\Infrastructure\ExifToolMetadataWriter;
use Mediarama\Export\Infrastructure\ExifToolSanitizedCopyArguments;
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


function visualSignature(string $convertBinary, string $path): string
{
    $process = new Process([
        $convertBinary,
        $path,
        '-auto-orient',
        '-strip',
        '-format',
        '%wx%h:%#',
        'info:',
    ]);
    $process->setTimeout(30.0);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            "Unable to calculate visual signature for %s: %s",
            $path,
            trim($process->getErrorOutput()),
        ));
    }

    $signature = trim($process->getOutput());
    requireCondition($signature !== '', 'Visual signature is empty for '.$path.'.');

    return $signature;
}


function createImageFixture(
    string $name,
    string $path,
    string $root,
    string $convertBinary,
): void {
    if (!in_array($name, ['avif', 'heic'], true)) {
        runCommand([
            $convertBinary,
            '-size',
            '64x48',
            'xc:white',
            $path,
        ], 30.0);

        return;
    }

    $png = $root.'/heif-source-'.$name.'.png';
    runCommand([
        $convertBinary,
        '-size',
        '64x48',
        'xc:white',
        $png,
    ], 30.0);

    $command = ['heif-enc', $png];
    if ($name === 'avif') {
        $command[] = '-A';
    }
    $command[] = '-o';
    $command[] = $path;

    runCommand($command, 30.0);
    @unlink($png);
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
$writer = new ExifToolMetadataWriter(
    $process,
    new ExifToolMetadataArguments(),
    new ExifToolSanitizedCopyArguments(),
);
$iccProfile = '/usr/share/color/icc/sRGB.icc';
requireCondition(is_file($iccProfile), 'CI sRGB ICC fixture is missing.');

try {
    foreach ($formats as $name => $format) {
        $extension = $format['extension'];
        $mimeType = $format['mime'];
        $sourceName = 'source-'.$name.'.'.$extension;
        $sourcePath = $root.'/'.$sourceName;

        createImageFixture($name, $sourcePath, $root, $convertBinary);

        requireCondition(is_file($sourcePath) && filesize($sourcePath) > 0, 'Runtime fixture encoder did not create '.$name.' fixture.');

        runCommand([
            $exiftoolBinary,
            '-overwrite_original',
            '-XMP-dc:Title=Embedded '.$name,
            '-XMP-dc:Creator=Fixture Creator',
            '-XMP-dc:Rights=Fixture Copyright',
            '-XMP-iptcCore:Location=Vienna Embedded',
            '-XMP-photoshop:Instructions=PRIVATE-WORKFLOW-'.$name,
            '-XMP-xmp:CreatorTool=PRIVATE-TOOL-'.$name,
            '-XMP-exif:GPSLatitude=48.2082',
            '-XMP-exif:GPSLongitude=16.3738',
            '--',
            $sourcePath,
        ], 30.0);

        if ($name === 'jpeg') {
            // Keep a second legacy location representation in the source so the
            // Privacy-safe test proves that clearing the canonical XMP location
            // cannot reveal a fallback IPTC sublocation. Also preserve a real ICC
            // profile and EXIF orientation across the privacy scrub boundary.
            runCommand([
                $exiftoolBinary,
                '-overwrite_original',
                '-IPTC:Sub-location=Vienna Legacy',
                '-EXIF:Orientation#=6',
                '-EXIF:ColorSpace#=1',
                '-ICC_Profile<='.$iccProfile,
                '--',
                $sourcePath,
            ], 30.0);
        }

        if ($name === 'tiff') {
            // TIFF cannot drop structural IFD0 wholesale. Seed a common IFD0
            // descriptive field so the Privacy-safe path proves CommonIFD0 is
            // scrubbed without damaging the image-bearing directory.
            runCommand([
                $exiftoolBinary,
                '-overwrite_original',
                '-IFD0:Artist=PRIVATE-IFD0-TIFF',
                '--',
                $sourcePath,
            ], 30.0);
        }

        if ($name === 'png') {
            // gAMA/sRGB are display/color semantics and must survive the metadata
            // privacy scrub even though arbitrary PNG textual metadata must not.
            runCommand([
                $exiftoolBinary,
                '-overwrite_original',
                '-PNG:Gamma=2.2',
                '-PNG:SRGBRendering#=0',
                '--',
                $sourcePath,
            ], 30.0);
        }

        $sourceVisualSignature = visualSignature($convertBinary, $sourcePath);
        $sourceMetadataJson = $process->run([
            '-json',
            '-struct',
            '-G1',
            '-a',
            '-n',
            '--',
            $sourcePath,
        ]);
        requireCondition(
            str_contains($sourceMetadataJson, 'PRIVATE-WORKFLOW-'.$name),
            'Source fixture is missing inherited private metadata for '.$name.'.',
        );

        if ($name === 'tiff') {
            requireCondition(
                str_contains($sourceMetadataJson, 'PRIVATE-IFD0-TIFF'),
                'TIFF source fixture is missing inherited IFD0 metadata.',
            );
        }

        if ($name === 'png') {
            requireCondition(
                str_contains($sourceMetadataJson, '"PNG:Gamma": 2.2')
                && str_contains($sourceMetadataJson, '"PNG:SRGBRendering": 0'),
                'PNG source fixture is missing rendering-critical color metadata.',
            );
        }

        $sourceIccHash = null;
        if ($name === 'jpeg') {
            $sourceIcc = $process->run(['-b', '-ICC_Profile', '--', $sourcePath]);
            requireCondition($sourceIcc !== '', 'JPEG source fixture is missing ICC profile.');
            $sourceIccHash = hash('sha256', $sourceIcc);
        }

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
        $currentMetadataJson = $process->run([
            '-json',
            '-struct',
            '-G1',
            '-a',
            '-n',
            '--',
            $currentPath,
        ]);
        requireCondition(
            str_contains($currentMetadataJson, 'PRIVATE-WORKFLOW-'.$name),
            'Current export unexpectedly removed inherited source metadata for '.$name.'.',
        );
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
        requireCondition($privacy->locationName === null, 'Privacy-safe export retained descriptive location for '.$name.'.');
        requireCondition($privacy->latitude === null, 'Privacy-safe export retained latitude for '.$name.'.');
        requireCondition($privacy->longitude === null, 'Privacy-safe export retained longitude for '.$name.'.');

        $privacyMetadataJson = $process->run([
            '-json',
            '-struct',
            '-G1',
            '-a',
            '-n',
            '--',
            $privacyPath,
        ]);
        requireCondition(
            !str_contains($privacyMetadataJson, 'PRIVATE-WORKFLOW-'.$name)
            && !str_contains($privacyMetadataJson, 'PRIVATE-TOOL-'.$name),
            'Privacy-safe export retained inherited private metadata for '.$name.'.',
        );

        if ($name === 'tiff') {
            requireCondition(
                !str_contains($privacyMetadataJson, 'PRIVATE-IFD0-TIFF'),
                'Privacy-safe TIFF export retained common descriptive IFD0 metadata.',
            );
        }

        if ($name === 'png') {
            requireCondition(
                str_contains($privacyMetadataJson, '"PNG:Gamma": 2.2')
                && str_contains($privacyMetadataJson, '"PNG:SRGBRendering": 0'),
                'Privacy-safe PNG export did not preserve gamma/sRGB rendering semantics.',
            );
        }

        requireCondition(
            visualSignature($convertBinary, $privacyPath) === $sourceVisualSignature,
            'Privacy-safe metadata scrub changed rendered pixels/orientation for '.$name.'.',
        );

        if ($name === 'jpeg') {
            $privacyIcc = $process->run(['-b', '-ICC_Profile', '--', $privacyPath]);
            requireCondition(
                $privacyIcc !== ''
                && hash('sha256', $privacyIcc) === $sourceIccHash,
                'Privacy-safe JPEG export did not preserve the source ICC profile.',
            );

            $privacyOrientation = trim($process->run([
                '-s',
                '-s',
                '-s',
                '-n',
                '-Orientation',
                '--',
                $privacyPath,
            ]));
            requireCondition(
                $privacyOrientation === '6',
                'Privacy-safe JPEG export did not preserve EXIF orientation.',
            );
        }

        $source = fopen($sourcePath, 'rb');
        requireCondition($source !== false, 'Unable to reopen '.$name.' source for custom export.');
        $customStream = $writer->write(
            $source,
            $media,
            new MetadataExportPolicy(
                MetadataExportProfile::Custom,
                ['title'],
            ),
        );
        fclose($source);

        $customName = 'custom-'.$name.'.'.$extension;
        $customPath = $root.'/'.$customName;
        writeStreamToFile($customStream, $customPath);

        $custom = $inspector->inspect(new StorageObjectId('media', $customName));
        requireCondition($custom->title === 'Current '.$name, 'Custom export lost selected title for '.$name.'.');
        requireCondition($custom->description === null, 'Custom export retained unselected description for '.$name.'.');
        requireCondition($custom->creator === null, 'Custom export retained unselected creator for '.$name.'.');
        requireCondition($custom->copyright === null, 'Custom export retained unselected copyright for '.$name.'.');
        requireCondition($custom->locationName === null, 'Custom export retained unselected location for '.$name.'.');
        requireCondition(
            $custom->latitude === null && $custom->longitude === null,
            'Custom export retained unselected GPS for '.$name.'.',
        );

        $customMetadataJson = $process->run([
            '-json',
            '-struct',
            '-G1',
            '-a',
            '-n',
            '--',
            $customPath,
        ]);
        requireCondition(
            !str_contains($customMetadataJson, 'PRIVATE-WORKFLOW-'.$name)
            && !str_contains($customMetadataJson, 'PRIVATE-TOOL-'.$name),
            'Custom export retained inherited private metadata for '.$name.'.',
        );

        if ($name === 'tiff') {
            requireCondition(
                !str_contains($customMetadataJson, 'PRIVATE-IFD0-TIFF'),
                'Custom TIFF export retained inherited common IFD0 metadata.',
            );
        }

        if ($name === 'png') {
            requireCondition(
                str_contains($customMetadataJson, '"PNG:Gamma": 2.2')
                && str_contains($customMetadataJson, '"PNG:SRGBRendering": 0'),
                'Custom PNG export did not preserve gamma/sRGB rendering semantics.',
            );
        }

        requireCondition(
            visualSignature($convertBinary, $customPath) === $sourceVisualSignature,
            'Custom metadata scrub changed rendered pixels/orientation for '.$name.'.',
        );

        echo 'OK '.$name.' metadata extract/current/privacy-safe/custom authoritative round-trip'.PHP_EOL;
    }
} finally {
    removeTree($root);
}
