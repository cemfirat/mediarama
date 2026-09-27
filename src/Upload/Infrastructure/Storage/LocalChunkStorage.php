<?php

declare(strict_types=1);

namespace Mediarama\Upload\Infrastructure\Storage;

use Mediarama\Upload\Application\ChunkStorage;
use Mediarama\Upload\Application\UploadProblem;
use Mediarama\Upload\Domain\UploadChunk;
use Mediarama\Upload\Domain\UploadFailureStage;
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
            throw new \RuntimeException('Unable to create upload chunk directory.');
        }

        $path = $this->chunkPath($sessionId, $chunk->index);
        $tmp = $path.'.part';

        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Unable to create chunk file.');
        }

        $hash = hash_init('sha256');
        $written = 0;

        try {
            while (!feof($stream)) {
                $buffer = fread($stream, 1024 * 1024);
                if ($buffer === false) {
                    throw new \RuntimeException('Unable to read chunk input.');
                }
                if ($buffer === '') {
                    continue;
                }
                $written += strlen($buffer);
                hash_update($hash, $buffer);
                if (fwrite($out, $buffer) === false) {
                    throw new \RuntimeException('Unable to write chunk.');
                }
            }
        } finally {
            fclose($out);
        }

        if ($written !== $chunk->size) {
            @unlink($tmp);
            throw UploadProblem::retryable(
                'chunk_size_mismatch',
                UploadFailureStage::Acquisition,
                'Chunk byte count does not match declared size.',
            );
        }

        if (hash_final($hash) !== $chunk->checksumSha256) {
            @unlink($tmp);
            throw UploadProblem::retryable(
                'chunk_checksum_mismatch',
                UploadFailureStage::Acquisition,
                'Chunk checksum mismatch.',
            );
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to finalize chunk.');
        }

        file_put_contents($path.'.json', json_encode([
            'index' => $chunk->index,
            'offset' => $chunk->offset,
            'size' => $chunk->size,
            'checksum' => $chunk->checksumSha256,
        ], JSON_THROW_ON_ERROR));
    }

    public function listChunks(Uuid $sessionId): array
    {
        $dir = $this->sessionDirectory($sessionId);
        if (!is_dir($dir)) {
            return [];
        }

        $chunks = [];
        foreach (glob($dir.'/chunk-*.json') ?: [] as $metaFile) {
            $data = json_decode((string) file_get_contents($metaFile), true, flags: JSON_THROW_ON_ERROR);
            $chunks[] = new UploadChunk(
                (int) $data['index'],
                (int) $data['offset'],
                (int) $data['size'],
                (string) $data['checksum'],
            );
        }

        usort($chunks, static fn (UploadChunk $a, UploadChunk $b): int => $a->index <=> $b->index);

        return $chunks;
    }

    public function assemble(Uuid $sessionId, int $expectedSize, string $targetStorageKey): void
    {
        $chunks = $this->listChunks($sessionId);
        if ($chunks === []) {
            throw UploadProblem::retryable(
                'upload_incomplete',
                UploadFailureStage::Assembly,
                'No upload chunks are available.',
            );
        }

        $target = rtrim($this->mediaRoot, '/').'/'.$targetStorageKey;
        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Unable to create temporary upload directory.');
        }

        $tmp = $target.'.assembling';
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Unable to create assembled upload.');
        }

        $expectedOffset = 0;
        try {
            foreach ($chunks as $chunk) {
                if ($chunk->offset !== $expectedOffset) {
                    throw UploadProblem::retryable(
                        'upload_incomplete',
                        UploadFailureStage::Assembly,
                        'Upload chunks are incomplete or out of sequence.',
                    );
                }

                $in = fopen($this->chunkPath($sessionId, $chunk->index), 'rb');
                if ($in === false) {
                    throw UploadProblem::retryable(
                        'upload_incomplete',
                        UploadFailureStage::Assembly,
                        'Upload chunk is missing.',
                    );
                }
                stream_copy_to_stream($in, $out);
                fclose($in);

                $expectedOffset += $chunk->size;
            }
        } finally {
            fclose($out);
        }

        if ($expectedOffset !== $expectedSize) {
            @unlink($tmp);
            throw UploadProblem::retryable(
                'upload_incomplete',
                UploadFailureStage::Assembly,
                'Assembled upload size does not match expected size.',
            );
        }

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to finalize assembled upload.');
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
