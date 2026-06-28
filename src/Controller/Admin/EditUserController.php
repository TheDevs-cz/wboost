<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\FormData\EditUserFormData;
use WBoost\Web\FormType\EditUserFormType;
use WBoost\Web\Message\User\EditUser;
use WBoost\Web\Value\UserRoleChoice;

final class EditUserController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/admin/users/{id}/edit', name: 'admin_edit_user')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'id')]
        User $user,
    ): Response {
        $data = EditUserFormData::fromUser($user);

        $form = $this->createForm(EditUserFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(new EditUser(
                $user->id->toString(),
                $data->name,
                UserRoleChoice::toRoles($data->role),
            ));

            $this->addFlash('success', 'Uživatel byl upraven.');

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/edit_user.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }
}
