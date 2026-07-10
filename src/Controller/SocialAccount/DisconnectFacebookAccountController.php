<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\SocialAccount\DisconnectFacebookAccount;

final class DisconnectFacebookAccountController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/user-profile/facebook/disconnect', name: 'facebook_disconnect')]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $this->bus->dispatch(new DisconnectFacebookAccount($user->id->toString()));

        $this->addFlash('success', 'Facebookový účet byl odpojen.');

        return $this->redirectToRoute('user_profile');
    }
}
