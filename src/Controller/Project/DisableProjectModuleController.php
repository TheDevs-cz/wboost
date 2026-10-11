<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Project\DisableProjectModule;
use WBoost\Web\Value\ProjectModule;

/**
 * Admin-only POST + CSRF from the project's "Moduly projektu" card. Hides the
 * module; no data is deleted.
 */
final class DisableProjectModuleController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'project_module_disable';

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(
        path: '/project/{projectId}/modules/{module}/disable',
        name: 'project_module_disable',
        requirements: ['module' => new EnumRequirement(ProjectModule::class)],
        methods: ['POST'],
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        ProjectModule $module,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $this->bus->dispatch(new DisableProjectModule($project->id, $module, $user->id));

        $this->addFlash('success', sprintf('Modul %s je vypnutý. Data zůstala zachována.', $module->label()));

        return $this->redirect($this->generateUrl('edit_project', ['id' => $project->id->toString()]) . '#project-modules');
    }
}
