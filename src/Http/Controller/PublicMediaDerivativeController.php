<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Mediarama\Media\Application\MediaDerivativeRepository;
use Mediarama\Media\Application\MediaStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PublicMediaDerivativeController extends AbstractController
{
    private const ALLOWED_PROFILES = ['thumbnail', 'preview', 'large'];

    public function __construct(
        private readonly PublicGalleryQuery $gallery,
        private readonly MediaDerivativeRepository $derivatives,
        private readonly MediaStorage $storage,
    ) {
    }

    #[Route(
        '/media/{id}/derivatives/v{version}/{profile}',
        name: 'public_media_derivative',
        requirements: ['version' => '\\d+', 'profile' => 'thumbnail|preview|large'],
        methods: ['GET'],
    )]
    public function __invoke(string $id, int $version, string $profile): StreamedResponse
    {
        if ($version < 1 || !in_array($profile, self::ALLOWED_PROFILES, true)) {
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

        $derivative = $this->derivatives->find($mediaId, 'image', $profile, $version);
        if ($derivative === null) {
            throw $this->createNotFoundException();
        }

        $response = new StreamedResponse(function () use ($derivative): void {
            $stream = $this->storage->read($derivative->storage);

            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new \RuntimeException('Unable to stream media derivative.');
                    }

                    echo $chunk;
                }
            } finally {
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', $derivative->mimeType);
        $response->headers->set('Content-Length', (string) $derivative->byteSize);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
