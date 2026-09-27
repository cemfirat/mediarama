<?php

declare(strict_types=1);

namespace Mediarama\Http\Controller\Admin;

use Mediarama\Security\Application\CurrentUser;
use Mediarama\Security\Application\SystemAdministrationPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly SystemAdministrationPolicy $administration,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        try {
            $user = $this->currentUser->requireUser();
        } catch (\DomainException) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        if (!$this->administration->canAdminister($user->id)) {
            throw $this->createAccessDeniedException(
                'System administration permission is required.',
            );
        }

        $response = $this->render('@Mediarama/admin/dashboard.html.twig');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
