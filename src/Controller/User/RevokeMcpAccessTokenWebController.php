<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\User;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Mcp\RevokeMcpAccessToken;
use WBoost\Web\Repository\McpAccessTokenRepository;

/**
 * Kill one of the current user's personal access tokens from the web UI — the
 * second half of "Propojené aplikace" being a complete answer to "what can
 * reach my projects?".
 *
 * The token is loaded and its OWNER checked before anything is dispatched:
 * {@see RevokeMcpAccessToken} carries only a token id (it was written for the
 * operator console, which is not scoped to anyone), so the ownership check has
 * to happen here or a user could revoke somebody else's token by guessing a
 * UUID.
 */
final class RevokeMcpAccessTokenWebController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private McpAccessTokenRepository $mcpAccessTokenRepository,
    ) {
    }

    #[Route(
        path: '/user-profile/connected-apps/tokens/{tokenId}/revoke',
        name: 'connected_app_token_revoke',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $tokenId, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('connected_app_token_revoke', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($tokenId)) {
            throw $this->createNotFoundException('Malformed token id.');
        }

        $token = $this->mcpAccessTokenRepository->get(Uuid::fromString($tokenId));

        // Not "access denied": a user must not be able to tell somebody else's
        // token id from a made-up one.
        if ($token->user->id->equals($user->id) === false) {
            throw $this->createNotFoundException('Token not found.');
        }

        $this->bus->dispatch(new RevokeMcpAccessToken($token->id));

        $this->addFlash('success', 'Přístupový token byl zneplatněn.');

        return $this->redirectToRoute('connected_apps');
    }
}
