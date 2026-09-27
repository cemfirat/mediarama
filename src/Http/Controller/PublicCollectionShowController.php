<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PublicCollectionShowController extends AbstractController
{
    public function __construct(private readonly PublicGalleryQuery $gallery)
    {
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

        return $this->render('@Mediarama/public/collections/show.html.twig', [
            'collection' => $collection,
            'children' => $this->gallery->childCollections($collectionId),
            'media' => $this->gallery->media($collectionId),
        ]);
    }
}
