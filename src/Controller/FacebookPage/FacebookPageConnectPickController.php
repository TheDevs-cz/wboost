<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\FacebookPage;

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
use WBoost\Web\Message\FacebookPage\ConnectProjectFacebookPage;
use WBoost\Web\Services\FacebookPage\FacebookPagePublishing;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\Security\TokenCrypto;

/**
 * Several Pages were shared — the project editor picks the one this project
 * publishes to. The pending grant (Page tokens encrypted) waits in the
 * session, single-use.
 */
final class FacebookPageConnectPickController extends AbstractController
{
    public const string SESSION_KEY = 'facebook_page_connect_pick';

    public function __construct(
        readonly private FacebookPagePublishing $publishing,
        readonly private TokenCrypto $tokenCrypto,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{projectId}/facebook/pick', name: 'project_facebook_pick', methods: ['GET', 'POST'])]
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
            $this->addFlash('danger', 'Výběr facebookové stránky vypršel. Propojte stránku prosím znovu.');

            return $back;
        }

        if ($request->isMethod('GET')) {
            return $this->render('project_facebook_pick.html.twig', [
                'project' => $project,
                'pages' => $pending['pages'],
            ]);
        }

        if (!$this->isCsrfTokenValid('project_facebook_pick', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $chosen = null;

        foreach ($pending['pages'] as $page) {
            if ($page['id'] === $request->request->getString('page')) {
                $chosen = $page;
            }
        }

        if ($chosen === null) {
            return $this->redirectToRoute('project_facebook_pick', ['projectId' => $project->id]);
        }

        $request->getSession()->remove(self::SESSION_KEY);

        try {
            $token = $this->tokenCrypto->decrypt($chosen['token']);
        } catch (TokenDecryptionFailed) {
            $this->addFlash('danger', 'Výběr facebookové stránky vypršel. Propojte stránku prosím znovu.');

            return $back;
        }

        $this->bus->dispatch(new ConnectProjectFacebookPage(
            $project->id->toString(),
            $user->id->toString(),
            $chosen['id'],
            $pending['ownerId'],
            $chosen['name'],
            $token,
            $pending['expiresAt'],
        ));

        $this->addFlash('success', sprintf('Facebooková stránka %s je propojena s projektem.', $chosen['name']));

        return $back;
    }

    /**
     * @return null|array{expiresAt: null|int, ownerId: null|string, pages: list<array{id: string, name: string, token: string}>}
     */
    private function pending(Request $request, Project $project): null|array
    {
        $pending = $request->getSession()->get(self::SESSION_KEY);

        if (!is_array($pending) || ($pending['projectId'] ?? null) !== $project->id->toString() || !is_array($pending['pages'] ?? null)) {
            return null;
        }

        $pages = [];

        foreach ($pending['pages'] as $page) {
            if (is_array($page) && is_string($page['id'] ?? null) && is_string($page['name'] ?? null) && is_string($page['token'] ?? null)) {
                $pages[] = ['id' => $page['id'], 'name' => $page['name'], 'token' => $page['token']];
            }
        }

        $expiresAt = $pending['expiresAt'] ?? null;
        $ownerId = $pending['ownerId'] ?? null;

        return $pages === [] ? null : [
            'expiresAt' => is_int($expiresAt) ? $expiresAt : null,
            'ownerId' => is_string($ownerId) ? $ownerId : null,
            'pages' => $pages,
        ];
    }
}
