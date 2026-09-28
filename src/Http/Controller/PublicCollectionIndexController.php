<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Collection\Application\PublicGalleryQuery;
use Mediarama\Platform\Application\PlatformSettingsRepository;
use Mediarama\Platform\Domain\SearchIndexPolicy;
use Mediarama\Seo\Application\PublicCollectionPageMetadataFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCollectionIndexController extends AbstractController
{
    public function __construct(
        private readonly PublicGalleryQuery $gallery,
        private readonly PlatformSettingsRepository $settings,
        private readonly PublicCollectionPageMetadataFactory $seo,
    ) {
    }

    #[Route('/collections', name: 'public_collections', methods: ['GET'])]
    public function __invoke(): Response
    {
        $settings = $this->settings->current();
        if (!$settings->publicPublishingEnabled) {
            throw $this->createNotFoundException();
        }

        $indexable = $settings->searchIndexDefault === SearchIndexPolicy::Index;
        $response = $this->render('@Mediarama/public/collections/index.html.twig', [
            'collections' => $this->gallery->rootCollections(),
            'seo' => $this->seo->index($indexable),
        ]);

        if (!$indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
