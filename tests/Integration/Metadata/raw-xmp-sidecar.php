<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Mediarama\Export\Application\ExportMetadataSidecar;
use Mediarama\Export\Application\MetadataExportPolicy;
use Mediarama\Export\Domain\MetadataExportProfile;
use Mediarama\Export\Infrastructure\ExifToolMetadataArguments;
use Mediarama\Export\Infrastructure\ExifToolXmpSidecarWriter;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Metadata\ExifToolMetadataParser;
use Mediarama\Media\Infrastructure\Metadata\ExifToolProcess;

function requireSidecar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

/** @param resource $stream */
function saveSidecar($stream, string $path): void
{
    $target = fopen($path, 'wb');
    if ($target === false) {
        throw new RuntimeException('Unable to open sidecar fixture output.');
    }

    try {
        if (stream_copy_to_stream($stream, $target) === false) {
            throw new RuntimeException('Unable to persist sidecar fixture output.');
        }
    } finally {
        fclose($target);
        fclose($stream);
    }
}

function mediaForRaw(string $filename): MediaAsset
{
    return MediaAsset::create(
        ownerId: null,
        original: new StorageObjectId('media', 'originals/test/source'),
        originalFilename: $filename,
        mimeType: 'application/octet-stream',
        mediaType: MediaType::Image,
        byteSize: 123,
        checksumSha256: str_repeat('a', 64),
    );
}

$exiftoolBinary = getenv('EXIFTOOL_BINARY') ?: 'exiftool';
$process = new ExifToolProcess($exiftoolBinary, 30.0);
$parser = new ExifToolMetadataParser();
$writer = new ExifToolXmpSidecarWriter($process, new ExifToolMetadataArguments());
$export = new ExportMetadataSidecar($writer);
$root = sys_get_temp_dir().'/mediarama-raw-sidecar-'.getmypid();

if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Unable to create RAW sidecar integration directory.');
}

try {
    foreach (['DNG', 'cr2', 'CR3', 'nef', 'NRW', 'arw', 'RAF', 'orf', 'rw2', 'PEF'] as $extension) {
        requireSidecar(
            $writer->supports(mediaForRaw('fixture.'.$extension)),
            'RAW extension '.$extension.' supports XMP sidecars',
        );
    }

    requireSidecar(
        !$writer->supports(mediaForRaw('fixture.jpg')),
        'non-RAW JPEG is not routed to RAW sidecar export',
    );

    $media = mediaForRaw('fixture.CR3');
    $media->title = 'RAW Sidecar Current';
    $media->description = 'Canonical metadata without RAW mutation';
    $media->creator = 'Mediarama Photographer';
    $media->copyright = 'Mediarama Test';
    $media->locationName = 'Vienna RAW';
    $media->latitude = 48.2082;
    $media->longitude = 16.3738;

    $currentPath = $root.'/current.xmp';
    saveSidecar(
        $export($media, new MetadataExportPolicy(MetadataExportProfile::Current)),
        $currentPath,
    );

    $currentJson = $process->run([
        '-json',
        '-struct',
        '-G1',
        '-a',
        '-n',
        '--',
        $currentPath,
    ]);
    $current = $parser->parse($currentJson);

    requireSidecar($current->title === 'RAW Sidecar Current', 'current sidecar writes title');
    requireSidecar($current->description === 'Canonical metadata without RAW mutation', 'current sidecar writes description');
    requireSidecar($current->creator === 'Mediarama Photographer', 'current sidecar writes creator');
    requireSidecar($current->copyright === 'Mediarama Test', 'current sidecar writes copyright');
    requireSidecar($current->locationName === 'Vienna RAW', 'current sidecar writes location name');
    requireSidecar($current->latitude !== null && abs($current->latitude - 48.2082) < 0.0001, 'current sidecar writes latitude');
    requireSidecar($current->longitude !== null && abs($current->longitude - 16.3738) < 0.0001, 'current sidecar writes longitude');

    $privacyPath = $root.'/privacy.xmp';
    saveSidecar($export($media, MetadataExportPolicy::privacySafe()), $privacyPath);
    $privacy = $parser->parse($process->run([
        '-json',
        '-struct',
        '-G1',
        '-a',
        '-n',
        '--',
        $privacyPath,
    ]));

    requireSidecar($privacy->title === 'RAW Sidecar Current', 'privacy-safe sidecar retains non-location descriptive metadata');
    requireSidecar($privacy->locationName === null, 'privacy-safe sidecar omits descriptive location');
    requireSidecar($privacy->latitude === null && $privacy->longitude === null, 'privacy-safe sidecar omits GPS');

    $customPath = $root.'/custom.xmp';
    saveSidecar(
        $export(
            $media,
            new MetadataExportPolicy(
                MetadataExportProfile::Custom,
                ['title', 'creator'],
            ),
        ),
        $customPath,
    );
    $custom = $parser->parse($process->run([
        '-json',
        '-struct',
        '-G1',
        '-a',
        '-n',
        '--',
        $customPath,
    ]));

    requireSidecar($custom->title === 'RAW Sidecar Current', 'custom sidecar includes selected title');
    requireSidecar($custom->creator === 'Mediarama Photographer', 'custom sidecar includes selected creator');
    requireSidecar($custom->copyright === null, 'custom sidecar omits unselected copyright');
    requireSidecar($custom->locationName === null, 'custom sidecar omits unselected location');
    requireSidecar($custom->latitude === null && $custom->longitude === null, 'custom sidecar omits unselected GPS');

    $customLocationPath = $root.'/custom-location.xmp';
    saveSidecar(
        $export(
            $media,
            new MetadataExportPolicy(
                MetadataExportProfile::Custom,
                ['location_name'],
            ),
        ),
        $customLocationPath,
    );
    $customLocation = $parser->parse($process->run([
        '-json',
        '-struct',
        '-G1',
        '-a',
        '-n',
        '--',
        $customLocationPath,
    ]));

    requireSidecar(
        $customLocation->locationName === 'Vienna RAW',
        'custom sidecar can explicitly include descriptive location',
    );
    requireSidecar(
        $customLocation->latitude === null && $customLocation->longitude === null,
        'custom descriptive-location opt-in does not infer or include GPS',
    );

    try {
        $export($media, new MetadataExportPolicy(MetadataExportProfile::Original));
        throw new RuntimeException('Original profile unexpectedly produced a sidecar.');
    } catch (DomainException) {
        echo 'OK original profile remains original-media-only'.PHP_EOL;
    }
} finally {
    foreach (glob($root.'/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($root);
}
