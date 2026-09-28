<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Mediarama\Seo\Application\PublicCollectionPageMetadataFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PublicCollectionShowController extends AbstractController
{
    public function __construct(
        private readonly PublicGalleryQuery $gallery,
        private readonly PublicCollectionPageMetadataFactory $seo,
    ) {
    }

    #[Route('/collections/{id}', name: 'public_collection_show', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        try {
            $collectionId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $collection = $this->gallery->collection($collectionId);
        if ($collection === null) {
            throw $this->createNotFoundException();
        }

        $coverIndexable = $collection->coverMediaId !== null
            && $collection->coverThumbnailVersion !== null
            && $this->gallery->isMediaIndexable($collection->coverMediaId);

        $response = $this->render('@Mediarama/public/collections/show.html.twig', [
            'collection' => $collection,
            'children' => $this->gallery->childCollections($collectionId),
            'media' => $this->gallery->media($collectionId),
            'seo' => $this->seo->collection($collection, $coverIndexable),
        ]);

        if (!$collection->indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
