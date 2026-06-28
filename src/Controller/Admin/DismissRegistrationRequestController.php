<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\RegistrationRequest;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\User\DismissRegistrationRequest;

final class DismissRegistrationRequestController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/admin/registration-requests/{id}/dismiss', name: 'admin_dismiss_registration_request')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        #[MapEntity(id: 'id')]
        RegistrationRequest $registrationRequest,
    ): Response {
        $this->bus->dispatch(new DismissRegistrationRequest($registrationRequest->id->toString()));

        $this->addFlash('success', 'Žádost o registraci byla zamítnuta.');

        return $this->redirectToRoute('admin_registration_requests');
    }
}
