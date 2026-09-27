<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller;

use Mediarama\Platform\Application\FirstRunSetup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly FirstRunSetup $setup)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): Response
    {
        if ($this->getUser() === null && $this->setup->state()->isPending()) {
            return $this->redirectToRoute('app_setup');
        }

        return $this->render('@Mediarama/public/home.html.twig', [
            'page_title' => 'Mediarama',
        ]);
    }
}
