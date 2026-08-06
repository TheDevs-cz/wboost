<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\User;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\OAuth2\RevokeOAuthClientApproval;

/**
 * Disconnect one application from the current user's account.
 *
 * POST + CSRF rather than a link: this ends a live integration, and a GET would
 * be triggerable by any image tag on any page. The client is named in the body
 * but never trusted as an authorisation — the message carries the CURRENT
 * user's id, and the handler only ever touches that user's approval and that
 * user's tokens, so naming someone else's connection does nothing.
 */
final class RevokeConnectedAppController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/user-profile/connected-apps/revoke', name: 'connected_app_revoke', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('connected_app_revoke', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $clientIdentifier = $request->request->getString('clientIdentifier');

        if ($clientIdentifier === '') {
            throw $this->createNotFoundException('No client was named.');
        }

        $this->bus->dispatch(new RevokeOAuthClientApproval($user->id, $clientIdentifier));

        $this->addFlash('success', 'Aplikace byla odpojena a její přístupové tokeny přestaly platit.');

        return $this->redirectToRoute('connected_apps');
    }
}
