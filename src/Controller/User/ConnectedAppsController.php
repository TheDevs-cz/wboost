<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\User;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetConnectedApps;
use WBoost\Web\Repository\McpAccessTokenRepository;

/**
 * "Propojené aplikace" — everything that can reach this user's projects
 * without being this user in a browser, and a way to cut each one off.
 *
 * ## Why personal access tokens are listed here too
 *
 * They are a different credential (created by an operator with
 * `app:mcp:token:create`, presented as a `wb_mcp_…` bearer, no browser flow at
 * all) — but from the account owner's side an OAuth connection and a PAT are
 * the same thing: an application that can read and change their work. A user
 * who wants to disconnect "that AI assistant" should not first have to work out
 * which of two mechanisms it happened to use, and a page that showed only half
 * of them would be actively misleading — it would answer "what has access to my
 * projects?" with an incomplete list.
 *
 * Creation stays where it is (the console): minting a PAT prints a secret
 * exactly once and is an operator act. Revocation does not need that
 * asymmetry — being able to kill what you can see is the whole point.
 */
final class ConnectedAppsController extends AbstractController
{
    public function __construct(
        readonly private GetConnectedApps $getConnectedApps,
        readonly private McpAccessTokenRepository $mcpAccessTokenRepository,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(path: '/user-profile/connected-apps', name: 'connected_apps', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        return $this->render('connected_apps.html.twig', [
            'apps' => $this->getConnectedApps->forUser($user),
            'tokens' => $this->mcpAccessTokenRepository->listForUser($user),
            'now' => $this->clock->now(),
        ]);
    }
}
