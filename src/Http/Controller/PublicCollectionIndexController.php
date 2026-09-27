<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Mediarama\Platform\Application\PublicDiscoveryPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCollectionIndexController extends AbstractController
{
    public function __construct(
        private readonly PublicGalleryQuery $gallery,
        private readonly PublicDiscoveryPolicy $discovery,
    ) {
    }

    #[Route('/collections', name: 'public_collections', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->discovery->publicPublishingEnabled()) {
            throw $this->createNotFoundException();
        }

        $indexable = $this->discovery->siteIndexable();
        $response = $this->render('@Mediarama/public/collections/index.html.twig', [
            'collections' => $this->gallery->rootCollections(),
            'robots_noindex' => !$indexable,
        ]);

        if (!$indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
