<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Message\TemplateGroup\DeleteTemplateGroup;
use WBoost\Web\Services\Security\TemplateGroupVoter;

final class DeleteTemplateGroupController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/delete', name: 'delete_template_group', methods: ['POST'])]
    #[IsGranted(TemplateGroupVoter::EDIT, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
        Request $request,
    ): Response {
        $project = $group->project;
        $deleteTemplates = $request->request->getBoolean('deleteTemplates');

        $this->bus->dispatch(
            new DeleteTemplateGroup(
                $group->id,
                $deleteTemplates,
            ),
        );

        $this->addFlash('success', $deleteTemplates
            ? 'Skupina smazána včetně šablon!'
            : 'Seskupení zrušeno, šablony zůstaly zachovány.');

        return $this->redirectToRoute('template_groups', [
            'projectId' => $project->id,
        ]);
    }
}
