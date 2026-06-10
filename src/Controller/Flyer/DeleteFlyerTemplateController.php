<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\Message\Flyer\DeleteFlyerTemplate;
use WBoost\Web\Services\Security\FlyerTemplateVoter;

final class DeleteFlyerTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/flyer-template/{templateId}/delete', name: 'delete_flyer_template')]
    #[IsGranted(FlyerTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        FlyerTemplate $template,
    ): Response {
        $project = $template->project;

        $this->bus->dispatch(
            new DeleteFlyerTemplate(
                $template->id,
            ),
        );

        $this->addFlash('success', 'Šablona smazána!');

        return $this->redirectToRoute('flyer_templates', [
            'projectId' => $project->id,
        ]);
    }
}
