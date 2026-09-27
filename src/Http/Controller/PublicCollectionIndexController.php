<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCollectionIndexController extends AbstractController
{
    public function __construct(private readonly PublicGalleryQuery $gallery)
    {
    }

    #[Route('/collections', name: 'public_collections', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Mediarama/public/collections/index.html.twig', [
            'collections' => $this->gallery->rootCollections(),
        ]);
    }
}
