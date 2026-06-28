<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\UserAlreadyRegistered;
use WBoost\Web\FormData\InviteUserFormData;
use WBoost\Web\FormType\InviteUserFormType;
use WBoost\Web\Message\User\InviteUser;
use WBoost\Web\Value\UserRoleChoice;

final class InviteUserController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/admin/users/invite', name: 'admin_invite_user')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(Request $request, #[CurrentUser] User $admin): Response
    {
        $data = new InviteUserFormData();

        // Prefill the e-mail when converting a registration request into an invite.
        $email = $request->query->get('email');
        if ($request->isMethod('GET') && is_string($email)) {
            $data->email = $email;
        }

        $form = $this->createForm(InviteUserFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->bus->dispatch(new InviteUser(
                    $data->email,
                    $data->name,
                    UserRoleChoice::toRoles($data->role),
                    $data->projectIds,
                    $admin->id->toString(),
                ));

                $this->addFlash('success', 'Pozvánka byla odeslána.');

                return $this->redirectToRoute('admin_users');
            } catch (HandlerFailedException $failedException) {
                $realException = $failedException->getPrevious();

                if ($realException instanceof UserAlreadyRegistered) {
                    $this->addFlash('danger', 'Uživatel s tímto e-mailem už je zaregistrován.');

                    return $this->redirectToRoute('admin_invite_user');
                }

                throw $realException ?? $failedException;
            }
        }

        return $this->render('admin/invite_user.html.twig', [
            'form' => $form,
        ]);
    }
}
