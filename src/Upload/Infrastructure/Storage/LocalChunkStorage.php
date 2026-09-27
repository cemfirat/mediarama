<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Storage;

use Mediarama\Upload\Application\ChunkStorage;
use Mediarama\Upload\Domain\UploadChunk;
use Mediarama\Upload\Domain\UploadFailureCode;
use Mediarama\Upload\Domain\UploadProblem;
use Symfony\Component\Uid\Uuid;

final readonly class LocalChunkStorage implements ChunkStorage
{
    public function __construct(private string $mediaRoot)
    {
    }

    public function writeChunk(Uuid $sessionId, UploadChunk $chunk, $stream): void
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Chunk input must be a stream.');
        }

        $dir = $this->sessionDirectory($sessionId);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw UploadProblem::fromFailure(UploadFailureCode::ChunkStorageUnavailable);
        }

        $path = $this->chunkPath($sessionId, $chunk->index);
        $tmp = $path.'.part';

        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw UploadProblem::fromFailure(UploadFailureCode::ChunkStorageUnavailable);
        }

        $hash = hash_init('sha256');
        $written = 0;

        try {
            while (!feof($stream)) {
                $buffer = fread($stream, 1024 * 1024);
                if ($buffer === false) {
                    throw UploadProblem::fromFailure(UploadFailureCode::ChunkStorageUnavailable);
                }
                if ($buffer === '') {
                    continue;
                }
                $written += strlen($buffer);
                hash_update($hash, $buffer);
                if (fwrite($out, $buffer) === false) {
                    throw UploadProblem::fromFailure(UploadFailureCode::ChunkStorageUnavailable);
                }
            }
        } finally {
            fclose($out);
        }

        if ($written !== $chunk->size) {
            @unlink($tmp);
            throw UploadProblem::fromFailure(UploadFailureCode::ChunkSizeMismatch);
        }

        if (hash_final($hash) !== $chunk->checksumSha256) {
            @unlink($tmp);
            throw UploadProblem::fromFailure(UploadFailureCode::ChunkChecksumMismatch);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw UploadProblem::fromFailure(UploadFailureCode::ChunkStorageUnavailable);
        }

        $metadata = json_encode([
            'index' => $chunk->index,
            'offset' => $chunk->offset,
            'size' => $chunk->size,
            'checksum' => $chunk->checksumSha256,
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($path.'.json', $metadata, LOCK_EX) === false) {
            @unlink($path);
            throw UploadProblem::fromFailure(UploadFailureCode::ChunkStorageUnavailable);
        }
    }

    public function listChunks(Uuid $sessionId): array
    {
        $dir = $this->sessionDirectory($sessionId);
        if (!is_dir($dir)) {
            return [];
        }

        $chunks = [];
        foreach (glob($dir.'/chunk-*.json') ?: [] as $metaFile) {
            $encoded = file_get_contents($metaFile);
            if ($encoded === false) {
                throw UploadProblem::fromFailure(UploadFailureCode::ChunksIncomplete);
            }

            try {
                $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \InvalidArgumentException('Chunk metadata must decode to an object.');
                }

                $chunks[] = new UploadChunk(
                    (int) ($data['index'] ?? -1),
                    (int) ($data['offset'] ?? -1),
                    (int) ($data['size'] ?? -1),
                    (string) ($data['checksum'] ?? ''),
                );
            } catch (\JsonException|\InvalidArgumentException $error) {
                throw UploadProblem::fromFailure(UploadFailureCode::ChunksIncomplete, $error);
            }
        }

        usort($chunks, static fn (UploadChunk $a, UploadChunk $b): int => $a->index <=> $b->index);

        return $chunks;
    }

    public function assemble(Uuid $sessionId, int $expectedSize, string $targetStorageKey): void
    {
        $chunks = $this->listChunks($sessionId);
        if ($chunks === []) {
            throw UploadProblem::fromFailure(UploadFailureCode::ChunksIncomplete);
        }

        $target = rtrim($this->mediaRoot, '/').'/'.$targetStorageKey;
        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
            throw UploadProblem::fromFailure(UploadFailureCode::AssemblyStorageUnavailable);
        }

        $tmp = $target.'.assembling';
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw UploadProblem::fromFailure(UploadFailureCode::AssemblyStorageUnavailable);
        }

        $expectedOffset = 0;

        try {
            try {
                foreach ($chunks as $chunk) {
                    if ($chunk->offset !== $expectedOffset) {
                        throw UploadProblem::fromFailure(UploadFailureCode::ChunksIncomplete);
                    }

                    $in = fopen($this->chunkPath($sessionId, $chunk->index), 'rb');
                    if ($in === false) {
                        throw UploadProblem::fromFailure(UploadFailureCode::ChunksIncomplete);
                    }

                    try {
                        $copied = stream_copy_to_stream($in, $out);
                    } finally {
                        fclose($in);
                    }

                    if ($copied !== $chunk->size) {
                        throw UploadProblem::fromFailure(UploadFailureCode::ChunksIncomplete);
                    }

                    $expectedOffset += $chunk->size;
                }

                if ($expectedOffset !== $expectedSize) {
                    throw UploadProblem::fromFailure(UploadFailureCode::ChunksIncomplete);
                }
            } finally {
                fclose($out);
            }
        } catch (\Throwable $error) {
            @unlink($tmp);
            throw $error;
        }

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw UploadProblem::fromFailure(UploadFailureCode::AssemblyStorageUnavailable);
        }
    }

    public function deleteSessionChunks(Uuid $sessionId): void
    {
        $dir = $this->sessionDirectory($sessionId);
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($dir);
    }

    private function sessionDirectory(Uuid $sessionId): string
    {
        return rtrim($this->mediaRoot, '/').'/chunks/'.$sessionId->toRfc4122();
    }

    private function chunkPath(Uuid $sessionId, int $index): string
    {
        return $this->sessionDirectory($sessionId).'/chunk-'.$index;
    }
}
