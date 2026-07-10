<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Profile-page "connect Facebook" flow: asks for the publishing scopes
 * (Pages + Instagram) on top of identity. `auth_type=rerequest` makes the
 * dialog re-offer scopes the user previously declined.
 */
final class FacebookConnectStartController extends AbstractController
{
    /**
     * Everything publishing needs: list the user's Pages (+ their linked
     * Instagram accounts), read engagement metadata, and create posts.
     */
    public const array PUBLISHING_SCOPES = [
        'public_profile',
        'email',
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'instagram_basic',
        'instagram_content_publish',
    ];

    public function __construct(
        readonly private ClientRegistry $clientRegistry,
    ) {
    }

    #[Route(path: '/oauth/facebook/connect', name: 'oauth_facebook_connect')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(): Response
    {
        return $this->clientRegistry
            ->getClient('facebook_connect')
            ->redirect(self::PUBLISHING_SCOPES, ['auth_type' => 'rerequest']);
    }
}
