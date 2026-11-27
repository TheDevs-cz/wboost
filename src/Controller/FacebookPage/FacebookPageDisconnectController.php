<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\FacebookPage;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\Project\DisconnectProjectSocialAccount;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\SocialPlatform;

final class FacebookPageDisconnectController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{projectId}/facebook/disconnect', name: 'project_facebook_disconnect', methods: ['POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('project_facebook_disconnect', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->bus->dispatch(new DisconnectProjectSocialAccount($project->id->toString(), SocialPlatform::Facebook->value));
        $this->addFlash('success', 'Facebooková stránka byla od projektu odpojena.');

        return $this->redirectToRoute('project_social', ['projectId' => $project->id]);
    }
}
