<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Mediarama\Media\Application\MediaDerivativeRepository;
use Mediarama\Media\Application\MediaStorage;
use Mediarama\Media\Domain\MediaDerivative;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PublicVideoDerivativeController extends AbstractController
{
    private const PROFILES = [
        'poster' => 'image/jpeg',
        'browser_mp4' => 'video/mp4',
    ];

    public function __construct(
        private readonly PublicGalleryQuery $gallery,
        private readonly MediaDerivativeRepository $derivatives,
        private readonly MediaStorage $storage,
    ) {
    }

    #[Route(
        '/media/{id}/video/v{version}/{profile}',
        name: 'public_video_derivative',
        requirements: ['version' => '\\d+', 'profile' => 'poster|browser_mp4'],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        string $id,
        int $version,
        string $profile,
    ): Response {
        if ($version < 1 || !isset(self::PROFILES[$profile])) {
            throw $this->createNotFoundException();
        }

        try {
            $mediaId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        if (!$this->gallery->canViewMedia($mediaId)) {
            throw $this->createNotFoundException();
        }

        $derivative = $this->derivatives->find($mediaId, 'video', $profile, $version);
        if (
            $derivative === null
            || $derivative->mimeType !== self::PROFILES[$profile]
        ) {
            throw $this->createNotFoundException();
        }

        $response = $profile === 'browser_mp4'
            ? $this->videoResponse($request, $derivative)
            : $this->fullResponse($derivative);

        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if (!$this->gallery->isMediaIndexable($mediaId)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    private function fullResponse(MediaDerivative $derivative): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($derivative): void {
            $stream = $this->storage->read($derivative->storage);

            try {
                $this->streamBytes($stream, 0, $derivative->byteSize);
            } finally {
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', $derivative->mimeType);
        $response->headers->set('Content-Length', (string) $derivative->byteSize);

        return $response;
    }

    private function videoResponse(
        Request $request,
        MediaDerivative $derivative,
    ): Response {
        $size = $derivative->byteSize;
        if ($size < 1) {
            throw $this->createNotFoundException();
        }

        try {
            $range = $this->parseRange($request->headers->get('Range'), $size);
        } catch (\InvalidArgumentException) {
            $response = new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE);
            $response->headers->set('Content-Range', 'bytes */'.$size);
            $response->headers->set('Accept-Ranges', 'bytes');
            $response->headers->set('Content-Type', $derivative->mimeType);

            return $response;
        }

        $start = $range['start'];
        $end = $range['end'];
        $length = $end - $start + 1;
        $rangeRequested = trim((string) $request->headers->get('Range', '')) !== '';

        $response = new StreamedResponse(
            function () use ($derivative, $start, $length): void {
                $stream = $this->storage->read($derivative->storage);

                try {
                    $this->streamBytes($stream, $start, $length);
                } finally {
                    fclose($stream);
                }
            },
            $rangeRequested ? Response::HTTP_PARTIAL_CONTENT : Response::HTTP_OK,
        );

        $response->headers->set('Content-Type', $derivative->mimeType);
        $response->headers->set('Content-Length', (string) $length);
        $response->headers->set('Accept-Ranges', 'bytes');

        if ($rangeRequested) {
            $response->headers->set(
                'Content-Range',
                sprintf('bytes %d-%d/%d', $start, $end, $size),
            );
        }

        return $response;
    }

    /**
     * @return array{start:int,end:int}
     */
    private function parseRange(?string $header, int $size): array
    {
        if ($header === null || trim($header) === '') {
            return ['start' => 0, 'end' => $size - 1];
        }

        $header = trim($header);
        if (
            str_contains($header, ',')
            || preg_match('/^bytes=(\\d*)-(\\d*)$/D', $header, $match) !== 1
            || ($match[1] === '' && $match[2] === '')
        ) {
            throw new \InvalidArgumentException('Unsupported byte range.');
        }

        if ($match[1] === '') {
            $suffixLength = (int) $match[2];
            if ($suffixLength < 1) {
                throw new \InvalidArgumentException('Invalid suffix byte range.');
            }

            return [
                'start' => max(0, $size - $suffixLength),
                'end' => $size - 1,
            ];
        }

        $start = (int) $match[1];
        if ($start < 0 || $start >= $size) {
            throw new \InvalidArgumentException('Byte range starts outside the resource.');
        }

        $end = $match[2] === ''
            ? $size - 1
            : min((int) $match[2], $size - 1);

        if ($end < $start) {
            throw new \InvalidArgumentException('Invalid byte range end.');
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @param resource $stream */
    private function streamBytes($stream, int $start, int $length): void
    {
        if ($start > 0) {
            $metadata = stream_get_meta_data($stream);
            $seekable = (bool) ($metadata['seekable'] ?? false);

            if ($seekable && fseek($stream, $start, SEEK_SET) === 0) {
                // Positioned efficiently.
            } else {
                $remaining = $start;
                while ($remaining > 0 && !feof($stream)) {
                    $chunk = fread($stream, min(1024 * 1024, $remaining));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('Unable to seek media stream.');
                    }
                    $remaining -= strlen($chunk);
                }

                if ($remaining !== 0) {
                    throw new \RuntimeException('Media stream ended before requested range.');
                }
            }
        }

        $remaining = $length;
        while ($remaining > 0 && !feof($stream)) {
            $chunk = fread($stream, min(1024 * 1024, $remaining));
            if ($chunk === false) {
                throw new \RuntimeException('Unable to stream video derivative.');
            }
            if ($chunk === '') {
                break;
            }

            echo $chunk;
            $remaining -= strlen($chunk);
        }

        if ($remaining !== 0) {
            throw new \RuntimeException('Video derivative ended before expected content length.');
        }
    }
}
