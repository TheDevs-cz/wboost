<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\FacebookNotConnected;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\Meta\GetFacebookDestinations;

/**
 * JSON feed for the fill-page publish modal: the user's Facebook Pages and
 * their linked Instagram accounts. Page access tokens NEVER leave the server
 * — only ids/names are serialized.
 */
final class FacebookDestinationsController extends AbstractController
{
    public function __construct(
        readonly private GetFacebookDestinations $destinations,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/social/facebook/destinations', name: 'social_facebook_destinations', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        try {
            $pages = $this->destinations->pagesFor($user);
        } catch (FacebookNotConnected | FacebookTokenExpired) {
            return new JsonResponse(['connected' => false, 'reconnect' => true, 'pages' => []]);
        } catch (MetaApiError $exception) {
            $this->logger->warning('Fetching Facebook destinations failed.', ['exception' => $exception]);

            return new JsonResponse(
                ['connected' => true, 'error' => $exception->userMessage(), 'pages' => []],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse([
            'connected' => true,
            'pages' => array_map(static fn ($page): array => [
                'id' => $page->id,
                'name' => $page->name,
                'instagram' => $page->hasInstagram() ? [
                    'id' => $page->instagramUserId,
                    'username' => $page->instagramUsername,
                ] : null,
            ], $pages),
        ]);
    }
}
