<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mediarama\Media\Application\GenerateVideoDerivatives;
use Mediarama\Media\Application\VideoPlaybackProfile;
use Mediarama\Media\Application\VideoPosterProfile;
use Mediarama\Media\Domain\MediaAsset;
use Mediarama\Media\Domain\MediaType;
use Mediarama\Media\Domain\StorageObjectId;
use Mediarama\Media\Infrastructure\Persistence\DbalDerivativeCleanupRepository;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaAssetRepository;
use Mediarama\Media\Infrastructure\Persistence\DbalMediaDerivativeRepository;
use Mediarama\Media\Infrastructure\Persistence\PostgresMediaDerivativeRegenerationLock;
use Mediarama\Media\Infrastructure\Probe\FfprobeProcess;
use Mediarama\Media\Infrastructure\Probe\FfprobeVideoInspector;
use Mediarama\Media\Infrastructure\Storage\LocalMediaStorage;
use Mediarama\Media\Infrastructure\Video\FfmpegProcess;
use Mediarama\Media\Infrastructure\Video\FfmpegVideoDerivativeGenerator;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

function requireVideoProcessing(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'OK '.$message.PHP_EOL;
}

/** @param list<string> $command */
function runVideoTool(array $command): void
{
    $process = new Process($command);
    $process->setTimeout(60.0);
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

function removeVideoTree(string $path): void
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

$databaseUrl = (string) getenv('DATABASE_URL');
$mediaRoot = rtrim((string) getenv('MEDIA_STORAGE_PATH'), DIRECTORY_SEPARATOR);
$ffmpegBinary = trim((string) getenv('FFMPEG_BINARY'));
$ffprobeBinary = trim((string) getenv('FFPROBE_BINARY'));

if ($databaseUrl === '' || $mediaRoot === '' || $ffmpegBinary === '' || $ffprobeBinary === '') {
    throw new RuntimeException('Video integration test requires database, storage, FFmpeg and FFprobe configuration.');
}

$dsn = new DsnParser([
    'postgresql' => 'pdo_pgsql',
    'postgres' => 'pdo_pgsql',
]);
$db = DriverManager::getConnection($dsn->parse($databaseUrl));

$mediaId = Uuid::v7();
$fixture = sys_get_temp_dir().'/mediarama-video-processing-'.$mediaId->toRfc4122().'.mp4';
$source = new StorageObjectId(
    'media',
    'originals/video-processing/'.$mediaId->toRfc4122().'/source',
);
$sourcePath = $mediaRoot.DIRECTORY_SEPARATOR.$source->key;
$derivativeRoot = $mediaRoot.DIRECTORY_SEPARATOR.'derivatives'.DIRECTORY_SEPARATOR.$mediaId->toRfc4122();

$storage = new LocalMediaStorage($mediaRoot);
$media = new DbalMediaAssetRepository($db);
$derivatives = new DbalMediaDerivativeRepository($db);
$cleanup = new DbalDerivativeCleanupRepository($db);
$lock = new PostgresMediaDerivativeRegenerationLock($db);
$ffprobe = new FfprobeProcess($ffprobeBinary, 30.0, 33554432, 5000000);
$ffmpeg = new FfmpegProcess($ffmpegBinary, 120.0);
$generator = new FfmpegVideoDerivativeGenerator($storage, $ffmpeg, $ffprobe);
$videos = new GenerateVideoDerivatives(
    $derivatives,
    $generator,
    $generator,
    $storage,
    $cleanup,
    $lock,
    new VideoPosterProfile('poster', 1280, 1280, 3),
    new VideoPlaybackProfile(
        'browser_mp4',
        1920,
        1080,
        'libx264',
        23,
        'medium',
        'aac',
        '128k',
    ),
    1,
);

try {
    runVideoTool([
        $ffmpegBinary,
        '-hide_banner',
        '-loglevel', 'error',
        '-y',
        '-f', 'lavfi',
        '-i', 'color=c=blue:s=320x180:r=25:d=1',
        '-f', 'lavfi',
        '-i', 'sine=frequency=1000:sample_rate=44100:duration=1',
        '-shortest',
        '-c:v', 'libx264',
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac',
        '-movflags', '+faststart',
        '-metadata', 'title=PRIVATE_GLOBAL_VIDEO_METADATA_SENTINEL',
        '-metadata:s:v:0', 'title=PRIVATE_VIDEO_STREAM_METADATA_SENTINEL',
        '-metadata:s:a:0', 'title=PRIVATE_AUDIO_STREAM_METADATA_SENTINEL',
        $fixture,
    ]);

    $sourceSize = filesize($fixture);
    $sourceChecksum = hash_file('sha256', $fixture);
    if ($sourceSize === false || $sourceChecksum === false) {
        throw new RuntimeException('Unable to inspect generated video fixture.');
    }

    $stream = fopen($fixture, 'rb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open generated video fixture.');
    }

    try {
        $storage->write($source, $stream, 'video/mp4');
    } finally {
        fclose($stream);
    }

    $asset = MediaAsset::createWithId(
        $mediaId,
        null,
        $source,
        'PRIVATE-source-video-name.mp4',
        'video/mp4',
        MediaType::Video,
        (int) $sourceSize,
        $sourceChecksum,
    );
    $media->save($asset);

    $inspector = new FfprobeVideoInspector($storage, $ffprobe);
    $properties = $inspector($asset);

    requireVideoProcessing(
        $properties->width === 320 && $properties->height === 180,
        'FFprobe persists real source video geometry',
    );
    requireVideoProcessing(
        $properties->durationMs !== null
        && $properties->durationMs >= 800
        && $properties->durationMs <= 1300,
        'FFprobe reports a plausible source video duration',
    );
    requireVideoProcessing($properties->hasAudio, 'FFprobe detects source audio');
    requireVideoProcessing($properties->videoCodec !== '', 'FFprobe reports the source video codec');

    $asset->setVideoProperties(
        $properties->width,
        $properties->height,
        $properties->durationMs,
    );
    $media->save($asset);

    $persisted = $media->get($mediaId);
    requireVideoProcessing(
        $persisted->width === 320
        && $persisted->height === 180
        && $persisted->durationMs === $properties->durationMs,
        'canonical video geometry and duration persist on MediaAsset',
    );

    $videos($persisted);

    $profiles = $db->fetchFirstColumn(
        <<<'SQL'
SELECT profile
FROM media_derivatives
WHERE media_id = :media
  AND kind = 'video'
  AND processing_version = 1
ORDER BY profile ASC
SQL,
        ['media' => $mediaId->toRfc4122()],
    );
    requireVideoProcessing(
        $profiles === ['browser_mp4', 'poster'],
        'initial video processing publishes one complete poster/playback generation',
    );

    $poster = $derivatives->find($mediaId, 'video', 'poster', 1);
    $playback = $derivatives->find($mediaId, 'video', 'browser_mp4', 1);
    requireVideoProcessing($poster !== null && $playback !== null, 'video derivative records are readable');
    requireVideoProcessing(
        $poster?->mimeType === 'image/jpeg'
        && $playback?->mimeType === 'video/mp4',
        'video presentation derivatives use explicit browser MIME types',
    );

    $posterPath = $storage->localPath($poster->storage);
    $playbackPath = $storage->localPath($playback->storage);
    $posterInfo = getimagesize($posterPath);
    requireVideoProcessing(
        $posterInfo !== false
        && (int) $posterInfo[0] > 0
        && (int) $posterInfo[1] > 0
        && (int) $posterInfo[0] <= 1280
        && (int) $posterInfo[1] <= 1280,
        'generated poster is a bounded decodable image',
    );

    $playbackProperties = $ffprobe->videoProperties($playbackPath);
    requireVideoProcessing(
        $playbackProperties->width === 320 && $playbackProperties->height === 180,
        'browser MP4 preserves source geometry without upscaling',
    );
    requireVideoProcessing(
        $playbackProperties->hasAudio,
        'browser MP4 preserves source audio when present',
    );
    requireVideoProcessing(
        $playbackProperties->videoCodec === 'h264',
        'browser MP4 uses the configured H.264 video codec',
    );

    $metadataProbe = new Process([
        $ffprobeBinary,
        '-v', 'error',
        '-show_entries', 'format_tags:stream_tags',
        '-of', 'json=c=1',
        $playbackPath,
    ]);
    $metadataProbe->setTimeout(30.0);
    $metadataProbe->run();
    requireVideoProcessing(
        $metadataProbe->isSuccessful(),
        'generated browser MP4 metadata is inspectable',
    );
    $renderedTags = $metadataProbe->getOutput();
    foreach ([
        'PRIVATE_GLOBAL_VIDEO_METADATA_SENTINEL',
        'PRIVATE_VIDEO_STREAM_METADATA_SENTINEL',
        'PRIVATE_AUDIO_STREAM_METADATA_SENTINEL',
    ] as $privateTag) {
        requireVideoProcessing(
            !str_contains($renderedTags, $privateTag),
            'generated browser MP4 strips source metadata sentinel '.$privateTag,
        );
    }

    $metadata = $playback->metadata;
    requireVideoProcessing(
        ($metadata['role'] ?? null) === 'browser_playback'
        && ($metadata['has_audio'] ?? null) === true
        && !str_contains(json_encode($metadata, JSON_THROW_ON_ERROR), 'PRIVATE-source-video-name.mp4'),
        'video derivative metadata contains technical output state without source filename leakage',
    );

    $videos($persisted);
    requireVideoProcessing(
        (int) $db->fetchOne(
            "SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media AND kind = 'video'",
            ['media' => $mediaId->toRfc4122()],
        ) === 2,
        'repeated initial video processing is idempotent',
    );

    $version = $videos->regenerate($persisted);
    requireVideoProcessing($version === 2, 'video regeneration allocates the next immutable generation');

    foreach ([1, 2] as $generation) {
        foreach ([
            'poster' => 'jpg',
            'browser_mp4' => 'mp4',
        ] as $profile => $extension) {
            requireVideoProcessing(
                is_file(sprintf(
                    '%s/v%d/%s.%s',
                    $derivativeRoot,
                    $generation,
                    $profile,
                    $extension,
                )),
                sprintf('video generation v%d %s remains addressable', $generation, $profile),
            );
        }
    }

    requireVideoProcessing(
        (int) $db->fetchOne(
            "SELECT COUNT(*) FROM media_derivatives WHERE media_id = :media AND kind = 'video'",
            ['media' => $mediaId->toRfc4122()],
        ) === 4,
        'regeneration persists both profiles as one new versioned set',
    );

    echo "Video processing and immutable derivative integration checks passed.".PHP_EOL;
} finally {
    $db->executeStatement(
        'DELETE FROM derivative_cleanup_jobs WHERE media_id = :media',
        ['media' => $mediaId->toRfc4122()],
    );
    $db->executeStatement(
        'DELETE FROM media_assets WHERE id = :media',
        ['media' => $mediaId->toRfc4122()],
    );

    @unlink($fixture);
    try {
        $storage->delete($source);
    } catch (Throwable) {
    }
    removeVideoTree($derivativeRoot);
    @rmdir(dirname($sourcePath));
    @rmdir(dirname(dirname($sourcePath)));
    $db->close();
}
