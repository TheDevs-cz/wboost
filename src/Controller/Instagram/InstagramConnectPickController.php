<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Instagram;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\TokenDecryptionFailed;
use WBoost\Web\Message\Instagram\ConnectProjectInstagram;
use WBoost\Web\Services\Instagram\InstagramPublishing;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\Security\TokenCrypto;

/**
 * The client shared SEVERAL Instagram accounts in Unified Login — the
 * project editor picks the one this project publishes to. The pending grant
 * (token encrypted) waits in the session, put there by
 * InstagramConnectCheckController; it is single-use.
 */
final class InstagramConnectPickController extends AbstractController
{
    public const string SESSION_KEY = 'instagram_connect_pick';

    public function __construct(
        readonly private InstagramPublishing $publishing,
        readonly private TokenCrypto $tokenCrypto,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{projectId}/instagram/pick', name: 'project_instagram_pick', methods: ['GET', 'POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        $back = $this->redirectToRoute('project_social', ['projectId' => $project->id]);
        $pending = $this->pending($request, $project);

        if ($pending === null || !$this->publishing->isAvailable()) {
            $this->addFlash('danger', 'Výběr instagramového účtu vypršel. Propojte Instagram prosím znovu.');

            return $back;
        }

        if ($request->isMethod('GET')) {
            return $this->render('project_instagram_pick.html.twig', [
                'project' => $project,
                'accounts' => $pending['accounts'],
            ]);
        }

        if (!$this->isCsrfTokenValid('project_instagram_pick', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $chosen = null;

        foreach ($pending['accounts'] as $account) {
            if ($account['id'] === $request->request->getString('account')) {
                $chosen = $account;
            }
        }

        if ($chosen === null) {
            return $this->redirectToRoute('project_instagram_pick', ['projectId' => $project->id]);
        }

        $request->getSession()->remove(self::SESSION_KEY);

        try {
            $token = $this->tokenCrypto->decrypt($pending['token']);
        } catch (TokenDecryptionFailed) {
            $this->addFlash('danger', 'Výběr instagramového účtu vypršel. Propojte Instagram prosím znovu.');

            return $back;
        }

        $this->bus->dispatch(new ConnectProjectInstagram(
            $project->id->toString(),
            $user->id->toString(),
            $chosen['id'],
            $pending['ownerId'],
            $chosen['username'],
            $chosen['name'],
            null,
            $token,
            $pending['expiresAt'],
            [],
        ));

        $this->addFlash('success', sprintf('Instagram @%s je propojen s projektem.', $chosen['username']));

        return $back;
    }

    /**
     * @return null|array{token: string, expiresAt: null|int, ownerId: null|string, accounts: list<array{id: string, username: string, name: null|string}>}
     */
    private function pending(Request $request, Project $project): null|array
    {
        $pending = $request->getSession()->get(self::SESSION_KEY);

        if (
            !is_array($pending)
            || ($pending['projectId'] ?? null) !== $project->id->toString()
            || !is_string($pending['token'] ?? null)
            || !is_array($pending['accounts'] ?? null)
        ) {
            return null;
        }

        $accounts = [];

        foreach ($pending['accounts'] as $account) {
            if (is_array($account) && is_string($account['id'] ?? null) && is_string($account['username'] ?? null)) {
                $name = $account['name'] ?? null;
                $accounts[] = ['id' => $account['id'], 'username' => $account['username'], 'name' => is_string($name) ? $name : null];
            }
        }

        $expiresAt = $pending['expiresAt'] ?? null;
        $ownerId = $pending['ownerId'] ?? null;

        return $accounts === [] ? null : [
            'token' => $pending['token'],
            'expiresAt' => is_int($expiresAt) ? $expiresAt : null,
            'ownerId' => is_string($ownerId) ? $ownerId : null,
            'accounts' => $accounts,
        ];
    }
}
