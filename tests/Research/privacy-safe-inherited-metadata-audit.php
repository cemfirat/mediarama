<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

/** @param list<string> $command
 *  @return array{ok:bool,stdout:string,stderr:string}
 */
function auditRun(array $command, float $timeout = 45.0): array
{
    $process = new Process($command);
    $process->setTimeout($timeout);
    $process->run();

    return [
        'ok' => $process->isSuccessful(),
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}

/** @param list<string> $command */
function auditRequire(array $command, string $label, float $timeout = 45.0): void
{
    $result = auditRun($command, $timeout);

    if (!$result['ok']) {
        throw new RuntimeException(sprintf(
            "%s failed: %s\n%s",
            $label,
            trim($result['stderr']),
            trim($result['stdout']),
        ));
    }
}

function auditRemoveTree(string $path): void
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

function auditCreateFixture(
    string $name,
    string $path,
    string $root,
    string $convert,
): void {
    if (!in_array($name, ['avif', 'heic'], true)) {
        auditRequire([
            $convert,
            '-size',
            '96x64',
            'gradient:#204060-#e0c090',
            $path,
        ], 'create '.$name.' fixture');

        return;
    }

    $png = $root.'/source-'.$name.'.png';
    auditRequire([
        $convert,
        '-size',
        '96x64',
        'gradient:#204060-#e0c090',
        $png,
    ], 'create '.$name.' PNG source');

    $command = ['heif-enc', $png];
    if ($name === 'avif') {
        $command[] = '-A';
    }
    $command[] = '-q';
    $command[] = '90';
    $command[] = '-o';
    $command[] = $path;

    try {
        auditRequire($command, 'encode '.$name.' fixture');
    } finally {
        @unlink($png);
    }
}

/** @return array<string,mixed> */
function auditMetadata(string $exiftool, string $path): array
{
    $result = auditRun([
        $exiftool,
        '-json',
        '-G1',
        '-a',
        '-u',
        '-api',
        'RequestAll=2',
        '-n',
        '--',
        $path,
    ], 30.0);

    if (!$result['ok']) {
        throw new RuntimeException('ExifTool metadata read failed: '.trim($result['stderr']));
    }

    $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
        throw new RuntimeException('Unexpected ExifTool audit JSON.');
    }

    return $decoded[0];
}

/** @param array<string,mixed> $metadata */
function auditFirst(array $metadata, array $keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $metadata)) {
            return $metadata[$key];
        }
    }

    return null;
}

/** @param array<string,mixed> $metadata @param list<string> $needles
 *  @return list<string>
 */
function auditLeakedNeedles(array $metadata, array $needles): array
{
    $json = json_encode($metadata, JSON_THROW_ON_ERROR);
    $leaks = [];

    foreach ($needles as $needle) {
        if (str_contains($json, $needle)) {
            $leaks[] = $needle;
        }
    }

    return $leaks;
}

$convert = getenv('IMAGEMAGICK_BINARY') ?: 'convert';
$identify = getenv('IMAGEMAGICK_IDENTIFY_BINARY') ?: 'identify';
$exiftool = getenv('EXIFTOOL_BINARY') ?: 'exiftool';
$root = sys_get_temp_dir().'/mediarama-privacy-audit-'.getmypid();

if (!mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('Unable to create Privacy-safe audit directory.');
}

$formats = [
    'jpeg' => 'jpg',
    'tiff' => 'tif',
    'png' => 'png',
    'webp' => 'webp',
    'avif' => 'avif',
    'heic' => 'heic',
];

$sensitiveValues = [
    'PRIVATE-LOCATION-87',
    'PRIVATE-OWNER-87',
    'PRIVATE-SERIAL-87',
    'PRIVATE-IMAGE-ID-87',
    'private87@example.test',
    'PRIVATE-DOCUMENT-ID-87',
    'PRIVATE-IPTC-CREATOR-87',
    'PRIVATE-PNG-TEXT-87',
];

try {
    echo "Privacy-safe inherited metadata research harness\n";
    echo "Candidate scrub: -all= -CommonIFD0= -tagsfromfile @ -ColorSpaceTags -Orientation + explicit canonical writes\n";

    foreach ($formats as $name => $extension) {
        $source = $root.'/source-'.$name.'.'.$extension;
        $scrubbed = $root.'/scrubbed-'.$name.'.'.$extension;

        try {
            auditCreateFixture($name, $source, $root, $convert);

            $seed = [
                $exiftool,
                '-overwrite_original',
                '-XMP-dc:Title=SOURCE-TITLE-87',
                '-XMP-dc:Description=SOURCE-DESCRIPTION-87',
                '-XMP-dc:Creator=PRIVATE-OWNER-87',
                '-XMP-dc:Rights=SOURCE-COPYRIGHT-87',
                '-XMP-iptcCore:Location=PRIVATE-LOCATION-87',
                '-XMP-exif:GPSLatitude=48.2082',
                '-XMP-exif:GPSLongitude=16.3738',
                '-XMP-iptcCore:CreatorWorkEmail=private87@example.test',
                '-XMP-xmpMM:DocumentID=PRIVATE-DOCUMENT-ID-87',
                '-ExifIFD:OwnerName=PRIVATE-OWNER-87',
                '-ExifIFD:SerialNumber=PRIVATE-SERIAL-87',
                '-ExifIFD:ImageUniqueID=PRIVATE-IMAGE-ID-87',
                '-Orientation#=6',
            ];

            if (in_array($name, ['jpeg', 'tiff'], true)) {
                $seed[] = '-IPTC:By-line=PRIVATE-IPTC-CREATOR-87';
                $seed[] = '-IPTC:Sub-location=PRIVATE-LOCATION-87';
            }

            if ($name === 'png') {
                $seed[] = '-PNG:Comment=PRIVATE-PNG-TEXT-87';
                $seed[] = '-PNG:Gamma=2.2';
                $seed[] = '-PNG:SRGBRendering=0';
            }

            $seed[] = '--';
            $seed[] = $source;

            $seedResult = auditRun($seed, 45.0);
            if (!$seedResult['ok']) {
                throw new RuntimeException('Metadata seed failed: '.trim($seedResult['stderr']));
            }

            if (!copy($source, $scrubbed)) {
                throw new RuntimeException('Unable to create generated audit copy.');
            }

            $sourceHashBefore = hash_file('sha256', $source);
            $before = auditMetadata($exiftool, $source);

            $scrub = auditRun([
                $exiftool,
                '-overwrite_original',
                '-all=',
                '-CommonIFD0=',
                '-tagsfromfile',
                '@',
                '-ColorSpaceTags',
                '-Orientation',
                '-XMP-dc:Title=RETAINED-TITLE-87',
                '-XMP-dc:Description=RETAINED-DESCRIPTION-87',
                '-XMP-dc:Creator=RETAINED-CREATOR-87',
                '-XMP-dc:Rights=RETAINED-COPYRIGHT-87',
                '--',
                $scrubbed,
            ], 45.0);

            $after = auditMetadata($exiftool, $scrubbed);
            $sourceHashAfter = hash_file('sha256', $source);

            $identifyBefore = auditRun([$identify, '-format', '%wx%h', $source], 30.0);
            $identifyAfter = auditRun([$identify, '-format', '%wx%h', $scrubbed], 30.0);

            $result = [
                'format' => $name,
                'seed_warnings' => trim($seedResult['stderr']),
                'scrub_command_ok' => $scrub['ok'],
                'scrub_warnings' => trim($scrub['stderr']),
                'source_immutable' => is_string($sourceHashBefore)
                    && $sourceHashBefore === $sourceHashAfter,
                'decodable_before' => $identifyBefore['ok'],
                'decodable_after' => $identifyAfter['ok'],
                'geometry_before' => trim($identifyBefore['stdout']),
                'geometry_after' => trim($identifyAfter['stdout']),
                'orientation_before' => auditFirst($before, [
                    'IFD0:Orientation',
                    'ExifIFD:Orientation',
                    'XMP-tiff:Orientation',
                    'QuickTime:Rotation',
                ]),
                'orientation_after' => auditFirst($after, [
                    'IFD0:Orientation',
                    'ExifIFD:Orientation',
                    'XMP-tiff:Orientation',
                    'QuickTime:Rotation',
                ]),
                'icc_before' => auditFirst($before, [
                    'ICC-header:ProfileDescription',
                    'ICC_Profile:ProfileDescription',
                ]),
                'icc_after' => auditFirst($after, [
                    'ICC-header:ProfileDescription',
                    'ICC_Profile:ProfileDescription',
                ]),
                'png_gamma_before' => auditFirst($before, ['PNG:Gamma']),
                'png_gamma_after' => auditFirst($after, ['PNG:Gamma']),
                'png_srgb_before' => auditFirst($before, ['PNG:SRGBRendering']),
                'png_srgb_after' => auditFirst($after, ['PNG:SRGBRendering']),
                'sensitive_leaks' => auditLeakedNeedles($after, $sensitiveValues),
                'retained_title' => auditFirst($after, ['XMP-dc:Title', 'XMP:Title']),
                'retained_description' => auditFirst($after, ['XMP-dc:Description', 'XMP:Description']),
                'retained_creator' => auditFirst($after, ['XMP-dc:Creator', 'XMP:Creator']),
                'retained_copyright' => auditFirst($after, ['XMP-dc:Rights', 'XMP:Rights']),
            ];

            echo 'AUDIT_RESULT '.json_encode(
                $result,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;
        } catch (Throwable $error) {
            echo 'AUDIT_RESULT '.json_encode([
                'format' => $name,
                'harness_error' => $error->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        }
    }
} finally {
    auditRemoveTree($root);
}
