<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\LandingLeadRepository;

final class AdminLandingLeadsController extends AbstractController
{
    public function __construct(
        readonly private LandingLeadRepository $landingLeadRepository,
    ) {
    }

    #[Route(path: '/admin/landing-leads', name: 'admin_landing_leads')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(): Response
    {
        return $this->render('admin/landing_leads.html.twig', [
            'leads' => $this->landingLeadRepository->newestFirst(),
        ]);
    }
}
