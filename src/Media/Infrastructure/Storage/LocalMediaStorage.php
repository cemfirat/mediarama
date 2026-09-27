<?php

declare(strict_types=1);

namespace Mediarama\Media\Infrastructure\Storage;

use DateTimeImmutable;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Application\StoredObject;
use Mediarama\Media\Domain\StorageObjectId;

final readonly class LocalMediaStorage implements MediaStorage
{
    public function __construct(private string $mediaRoot)
    {
    }

    public function write(StorageObjectId $id, $stream, ?string $contentType = null): StoredObject
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Storage input must be a readable stream.');
        }

        $path = $this->path($id);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create media storage directory.');
        }

        $temporary = $path.'.part-'.bin2hex(random_bytes(8));
        $target = fopen($temporary, 'xb');
        if ($target === false) {
            throw new \RuntimeException('Unable to create temporary storage object.');
        }

        try {
            $bytes = stream_copy_to_stream($stream, $target);
            if ($bytes === false) {
                throw new \RuntimeException('Unable to write storage object.');
            }
        } finally {
            fclose($target);
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to promote temporary local write.');
        }

        return $this->stat($id);
    }

    public function read(StorageObjectId $id)
    {
        $stream = fopen($this->path($id), 'rb');
        if ($stream === false) {
            throw new \DomainException('Storage object not found.');
        }

        return $stream;
    }

    public function exists(StorageObjectId $id): bool
    {
        return is_file($this->path($id));
    }

    public function stat(StorageObjectId $id): StoredObject
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            throw new \DomainException('Storage object not found.');
        }

        $size = filesize($path);
        if ($size === false) {
            throw new \RuntimeException('Unable to stat storage object.');
        }

        return new StoredObject(
            $id,
            $size,
            hash_file('sha256', $path) ?: null,
            mime_content_type($path) ?: null,
        );
    }

    public function delete(StorageObjectId $id): void
    {
        $path = $this->path($id);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Unable to delete storage object.');
        }
    }

    public function promote(StorageObjectId $temporary, StorageObjectId $permanent): StoredObject
    {
        $source = $this->path($temporary);
        $target = $this->path($permanent);

        if (is_file($target)) {
            return $this->stat($permanent);
        }

        if (!is_file($source)) {
            if (is_file($target)) {
                return $this->stat($permanent);
            }

            throw new \DomainException('Temporary storage object not found.');
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create permanent media directory.');
        }

        if (!@rename($source, $target)) {
            // Another finalizer may have won the rename race after the initial
            // target check. Treat the deterministic target as the successful result.
            if (is_file($target)) {
                return $this->stat($permanent);
            }

            throw new \RuntimeException('Unable to promote storage object.');
        }

        return $this->stat($permanent);
    }

    public function publicUrl(StorageObjectId $id): ?string
    {
        return null;
    }

    public function temporaryUrl(StorageObjectId $id, DateTimeImmutable $expiresAt): ?string
    {
        return null;
    }

    public function localPath(StorageObjectId $id): string
    {
        return $this->path($id);
    }

    private function path(StorageObjectId $id): string
    {
        if ($id->disk !== 'media') {
            throw new \InvalidArgumentException('Local storage only supports the media disk.');
        }

        return rtrim($this->mediaRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$id->key;
    }
}
