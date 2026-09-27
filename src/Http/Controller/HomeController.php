<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Platform\Application\PublicDiscoveryPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly PublicDiscoveryPolicy $discovery)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): Response
    {
        $indexable = $this->discovery->siteIndexable();

        $response = $this->render('@Mediarama/public/home.html.twig', [
            'page_title' => 'Mediarama',
            'public_publishing_enabled' => $this->discovery->publicPublishingEnabled(),
            'robots_noindex' => !$indexable,
        ]);

        if (!$indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
