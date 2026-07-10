<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Sign in with Facebook" entry point — redirects to the Facebook consent
 * dialog with identity scopes only (no publishing permissions at login).
 */
final class FacebookLoginStartController extends AbstractController
{
    public function __construct(
        readonly private ClientRegistry $clientRegistry,
    ) {
    }

    #[Route(path: '/oauth/facebook/login', name: 'oauth_facebook_login')]
    public function __invoke(#[CurrentUser] null|UserInterface $user = null): Response
    {
        if ($user !== null) {
            return $this->redirectToRoute('homepage');
        }

        return $this->clientRegistry->getClient('facebook_login')->redirect(['public_profile', 'email'], []);
    }
}
