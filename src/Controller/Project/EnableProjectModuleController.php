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
use WBoost\Web\Message\Project\EnableProjectModule;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\ProjectModule;

/**
 * POST + CSRF from the "Objevte další moduly" page (project editors) and the
 * admin's "Moduly projektu" card. A non-admin switching a module on mails the
 * admins. An editor lands on the new module; the admin card's form posts
 * `back=edit` and returns to the project settings.
 */
final class EnableProjectModuleController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'project_module_enable';

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(
        path: '/project/{projectId}/modules/{module}/enable',
        name: 'project_module_enable',
        requirements: ['module' => new EnumRequirement(ProjectModule::class)],
        methods: ['POST'],
    )]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
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

        $this->bus->dispatch(new EnableProjectModule($project->id, $module, $user->id));

        $this->addFlash('success', sprintf('Modul %s je zapnutý — najdete ho v levém menu.', $module->label()));

        if ($request->request->getString('back') === 'edit') {
            return $this->redirect($this->generateUrl('edit_project', ['id' => $project->id->toString()]) . '#project-modules');
        }

        [$route, $parameter] = $module->landingRoute();

        return $this->redirectToRoute($route, [$parameter => $project->id->toString()]);
    }
}
