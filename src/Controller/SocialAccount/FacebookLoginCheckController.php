<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Facebook redirects back here after the login dialog. The route exists only
 * as a target — FacebookAuthenticator intercepts every request to it before
 * the controller would run.
 *
 * @see \WBoost\Web\Services\Security\FacebookAuthenticator
 */
final class FacebookLoginCheckController extends AbstractController
{
    #[Route(path: '/oauth/facebook/check', name: 'oauth_facebook_check')]
    public function __invoke(): Response
    {
        throw new \LogicException('This route is intercepted by FacebookAuthenticator and should never execute.');
    }
}
