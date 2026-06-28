<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\RegistrationRequestRepository;

final class AdminRegistrationRequestsController extends AbstractController
{
    public function __construct(
        readonly private RegistrationRequestRepository $registrationRequestRepository,
    ) {
    }

    #[Route(path: '/admin/registration-requests', name: 'admin_registration_requests')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(): Response
    {
        return $this->render('admin/registration_requests.html.twig', [
            'requests' => $this->registrationRequestRepository->allPendingFirst(),
        ]);
    }
}
