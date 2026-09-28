<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Mediarama\Seo\Application\PublicMediaPageMetadataFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PublicMediaShowController extends AbstractController
{
    public function __construct(
        private readonly PublicGalleryQuery $gallery,
        private readonly PublicMediaPageMetadataFactory $seo,
    ) {
    }

    #[Route('/media/{id}', name: 'public_media_show', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        try {
            $mediaId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $media = $this->gallery->mediaAsset($mediaId);
        if ($media === null) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('@Mediarama/public/media/show.html.twig', [
            'media' => $media,
            'seo' => $this->seo->create($media),
        ]);

        if (!$media->indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
