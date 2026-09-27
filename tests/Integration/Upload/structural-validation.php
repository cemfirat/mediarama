<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Mediarama\Media\Application\MediaValidationRejected;
use Mediarama\Media\Application\MediaValidationUnavailable;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Image\ImageMagickFileGeometryInspector;
use Mediarama\Media\Infrastructure\Image\ImageMagickProcess;
use Mediarama\Media\Infrastructure\Image\ImageMagickResourceLimits;
use Mediarama\Media\Infrastructure\Probe\FfprobeProcess;
use Mediarama\Media\Infrastructure\Probe\LocalStoredMediaStructureValidator;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Mediarama\Upload\Application\UploadContentPolicy;
use Mediarama\Upload\Infrastructure\LocalContentInspector;
use Symfony\Component\Process\Process;

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable(): void $operation */
function requireValidationRejection(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (MediaValidationRejected) {
        return;
    }

    throw new RuntimeException($message);
}

/** @param callable(): void $operation */
function requireValidationUnavailable(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (MediaValidationUnavailable) {
        return;
    }

    throw new RuntimeException($message);
}

/** @param list<string> $arguments */
function runTool(array $arguments): void
{
    $process = new Process($arguments);
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

function writeFakeTool(string $path, string $body, bool $executable = true): void
{
    if (file_put_contents($path, "#!/bin/sh\n".$body."\n") === false) {
        throw new RuntimeException('Unable to create fake media tool.');
    }

    if (!chmod($path, $executable ? 0700 : 0600)) {
        throw new RuntimeException('Unable to set fake media tool permissions.');
    }
}

function storeFixture(LocalMediaStorage $storage, string $key, string $path): StorageObjectId
{
    $id = new StorageObjectId('media', $key);
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open structural validation fixture.');
    }

    try {
        $storage->write($id, $stream);
    } finally {
        fclose($stream);
    }

    return $id;
}

$convertBinary = trim((string) getenv('IMAGEMAGICK_BINARY'));
$identifyBinary = trim((string) getenv('IMAGEMAGICK_IDENTIFY_BINARY'));
$ffprobeBinary = trim((string) getenv('FFPROBE_BINARY'));
requireCondition($convertBinary !== '', 'IMAGEMAGICK_BINARY must be configured.');
requireCondition($identifyBinary !== '', 'IMAGEMAGICK_IDENTIFY_BINARY must be configured.');
requireCondition($ffprobeBinary !== '', 'FFPROBE_BINARY must be configured.');

$suffix = bin2hex(random_bytes(8));
$root = sys_get_temp_dir().'/mediarama-upload-structure-'.$suffix;
$fixtures = $root.'/fixtures';
$storageRoot = $root.'/storage';

if (!mkdir($fixtures, 0700, true) && !is_dir($fixtures)) {
    throw new RuntimeException('Unable to create upload structural-validation fixture directory.');
}

$image = $fixtures.'/valid.png';
$truncatedImage = $fixtures.'/truncated.png';
$audio = $fixtures.'/valid.mp3';
$video = $fixtures.'/valid.mp4';
$invalidAv = $fixtures.'/invalid.bin';
$slowIdentify = $fixtures.'/slow-identify';
$nonExecutableIdentify = $fixtures.'/non-executable-identify';
$invalidGeometry = $fixtures.'/invalid-geometry';
$slowFfprobe = $fixtures.'/slow-ffprobe';
$invalidJsonFfprobe = $fixtures.'/invalid-json-ffprobe';
$nonExecutableFfprobe = $fixtures.'/non-executable-ffprobe';
$missingIdentify = $fixtures.'/missing-identify';
$missingFfprobe = $fixtures.'/missing-ffprobe';

try {
    runTool([$convertBinary, '-size', '32x32', 'xc:white', $image]);

    $imageBytes = file_get_contents($image);
    if ($imageBytes === false || strlen($imageBytes) < 64) {
        throw new RuntimeException('Generated image fixture is unexpectedly small.');
    }
    requireCondition(
        file_put_contents($truncatedImage, substr($imageBytes, 0, intdiv(strlen($imageBytes), 2))) !== false,
        'Unable to create truncated image fixture.',
    );

    runTool([
        'ffmpeg', '-loglevel', 'error', '-y',
        '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
        '-t', '0.25', '-q:a', '9',
        $audio,
    ]);
    runTool([
        'ffmpeg', '-loglevel', 'error', '-y',
        '-f', 'lavfi', '-i', 'color=c=black:s=16x16:r=10:d=0.25',
        '-an', '-c:v', 'mpeg4', '-q:v', '5',
        $video,
    ]);
    requireCondition(file_put_contents($invalidAv, "not a media file\n") !== false, 'Unable to create invalid AV fixture.');

    writeFakeTool($slowIdentify, "sleep 2\nprintf '32 32 Undefined'");
    writeFakeTool($nonExecutableIdentify, "printf '32 32 Undefined'", false);
    writeFakeTool($invalidGeometry, "printf 'not-a-geometry-response'");
    writeFakeTool($slowFfprobe, "sleep 2\nprintf '{\"streams\":[{\"codec_type\":\"audio\"}]}'");
    writeFakeTool($invalidJsonFfprobe, "printf 'not-json'");
    writeFakeTool($nonExecutableFfprobe, "printf '{\"streams\":[]}'", false);

    $storage = new LocalMediaStorage($storageRoot);
    $contentInspector = new LocalContentInspector($storage);
    $contentPolicy = new UploadContentPolicy([
        'image/png',
        'audio/mpeg',
        'video/mp4',
    ]);

    $imageProcess = new ImageMagickProcess(
        new ImageMagickResourceLimits(),
        $convertBinary,
        $identifyBinary,
        30.0,
    );
    $validator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector($imageProcess),
        new FfprobeProcess($ffprobeBinary, 15.0, 33554432, 5000000),
    );

    $validImage = storeFixture($storage, 'temporary/valid-image/source', $image);
    $validImageContent = $contentInspector->inspect($validImage);
    $contentPolicy->assertAllowed($validImageContent);
    requireCondition($validImageContent->mediaType === MediaType::Image, 'Valid image was not classified as image.');
    $validator($validImage, $validImageContent->mediaType);

    $badImage = storeFixture($storage, 'temporary/truncated-image/source', $truncatedImage);
    $badImageContent = $contentInspector->inspect($badImage);
    requireCondition($badImageContent->mimeType === 'image/png', 'Truncated image no longer looks like image/png to MIME detection.');
    $contentPolicy->assertAllowed($badImageContent);
    requireValidationRejection(
        static fn () => $validator($badImage, $badImageContent->mediaType),
        'Truncated image passed structural validation.',
    );

    $validAudio = storeFixture($storage, 'temporary/valid-audio/source', $audio);
    $validAudioContent = $contentInspector->inspect($validAudio);
    $contentPolicy->assertAllowed($validAudioContent);
    requireCondition($validAudioContent->mediaType === MediaType::Audio, 'Valid audio was not classified as audio.');
    $validator($validAudio, $validAudioContent->mediaType);

    $validVideo = storeFixture($storage, 'temporary/valid-video/source', $video);
    $validVideoContent = $contentInspector->inspect($validVideo);
    $contentPolicy->assertAllowed($validVideoContent);
    requireCondition($validVideoContent->mediaType === MediaType::Video, 'Valid video was not classified as video.');
    $validator($validVideo, $validVideoContent->mediaType);

    $invalidAudio = storeFixture($storage, 'temporary/invalid-audio/source', $invalidAv);
    requireValidationRejection(
        static fn () => $validator($invalidAudio, MediaType::Audio),
        'Invalid audio structure passed FFprobe validation.',
    );

    $invalidVideo = new StorageObjectId('media', 'temporary/invalid-audio/source');
    requireValidationRejection(
        static fn () => $validator($invalidVideo, MediaType::Video),
        'Invalid video structure passed FFprobe validation.',
    );

    requireValidationRejection(
        static fn () => $validator($validAudio, MediaType::Video),
        'Audio-only media passed validation as video.',
    );

    $missingImageValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector(new ImageMagickProcess(
            new ImageMagickResourceLimits(),
            $convertBinary,
            $missingIdentify,
            0.25,
        )),
        new FfprobeProcess($ffprobeBinary, 15.0, 33554432, 5000000),
    );
    requireValidationUnavailable(
        static fn () => $missingImageValidator($validImage, MediaType::Image),
        'Missing ImageMagick identify binary was not classified as unavailable.',
    );

    $slowImageValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector(new ImageMagickProcess(
            new ImageMagickResourceLimits(),
            $convertBinary,
            $slowIdentify,
            0.1,
        )),
        new FfprobeProcess($ffprobeBinary, 15.0, 33554432, 5000000),
    );
    requireValidationUnavailable(
        static fn () => $slowImageValidator($validImage, MediaType::Image),
        'ImageMagick timeout was not classified as unavailable.',
    );

    $nonExecutableImageValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector(new ImageMagickProcess(
            new ImageMagickResourceLimits(),
            $convertBinary,
            $nonExecutableIdentify,
            0.25,
        )),
        new FfprobeProcess($ffprobeBinary, 15.0, 33554432, 5000000),
    );
    requireValidationUnavailable(
        static fn () => $nonExecutableImageValidator($validImage, MediaType::Image),
        'Non-executable ImageMagick identify binary was not classified as unavailable.',
    );

    $invalidGeometryValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector(new ImageMagickProcess(
            new ImageMagickResourceLimits(),
            $convertBinary,
            $invalidGeometry,
            2.0,
        )),
        new FfprobeProcess($ffprobeBinary, 15.0, 33554432, 5000000),
    );
    requireValidationRejection(
        static fn () => $invalidGeometryValidator($validImage, MediaType::Image),
        'Invalid ImageMagick geometry response was not classified as rejected input.',
    );

    $missingFfprobeValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector($imageProcess),
        new FfprobeProcess($missingFfprobe, 0.25, 33554432, 5000000),
    );
    requireValidationUnavailable(
        static fn () => $missingFfprobeValidator($validAudio, MediaType::Audio),
        'Missing FFprobe binary was not classified as unavailable.',
    );

    $nonExecutableFfprobeValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector($imageProcess),
        new FfprobeProcess($nonExecutableFfprobe, 0.25, 33554432, 5000000),
    );
    requireValidationUnavailable(
        static fn () => $nonExecutableFfprobeValidator($validAudio, MediaType::Audio),
        'Non-executable FFprobe binary was not classified as unavailable.',
    );

    $slowFfprobeValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector($imageProcess),
        new FfprobeProcess($slowFfprobe, 0.1, 33554432, 5000000),
    );
    requireValidationUnavailable(
        static fn () => $slowFfprobeValidator($validAudio, MediaType::Audio),
        'FFprobe timeout was not classified as unavailable.',
    );

    $invalidJsonValidator = new LocalStoredMediaStructureValidator(
        $storage,
        new ImageMagickFileGeometryInspector($imageProcess),
        new FfprobeProcess($invalidJsonFfprobe, 2.0, 33554432, 5000000),
    );
    requireValidationUnavailable(
        static fn () => $invalidJsonValidator($validAudio, MediaType::Audio),
        'Invalid FFprobe JSON was not classified as unavailable.',
    );

    requireValidationUnavailable(
        static fn () => $validator(
            new StorageObjectId('media', 'temporary/missing/source'),
            MediaType::Image,
        ),
        'Missing validation object was not classified as unavailable.',
    );

    echo "OK valid image structural validation\n";
    echo "OK MIME-looking truncated image rejected\n";
    echo "OK valid audio FFprobe validation\n";
    echo "OK valid video FFprobe validation\n";
    echo "OK invalid audio/video rejected\n";
    echo "OK stream-type mismatch rejected\n";
    echo "OK missing ImageMagick classified unavailable\n";
    echo "OK ImageMagick timeout classified unavailable\n";
    echo "OK non-executable ImageMagick classified unavailable\n";
    echo "OK invalid ImageMagick geometry response rejected\n";
    echo "OK missing/non-executable FFprobe classified unavailable\n";
    echo "OK FFprobe timeout classified unavailable\n";
    echo "OK invalid FFprobe response classified unavailable\n";
    echo "OK missing stored object classified unavailable\n";
} finally {
    removeTree($root);
}
