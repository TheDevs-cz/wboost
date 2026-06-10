<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\FormData\FlyerTemplateFormData;
use WBoost\Web\FormType\FlyerTemplateFormType;
use WBoost\Web\Message\Flyer\EditFlyerTemplate;
use WBoost\Web\Query\GetFlyerCategories;
use WBoost\Web\Services\Security\FlyerTemplateVoter;

final class EditFlyerTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private GetFlyerCategories $getFlyerCategories,
    ) {
    }

    #[Route(path: '/flyer-template/{templateId}/edit', name: 'edit_flyer_template')]
    #[IsGranted(FlyerTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        FlyerTemplate $template,
        Request $request,
    ): Response {
        $project = $template->project;
        $categories = $this->getFlyerCategories->allForProject($project->id);
        $data = new FlyerTemplateFormData();
        $data->name = $template->name;
        $data->category = $template->category?->id->toString();
        $form = $this->createForm(FlyerTemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new EditFlyerTemplate(
                    $template->id,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            $this->addFlash('success', 'Šablona upravena!');

            return $this->redirectToRoute('flyer_templates', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_flyer_template.html.twig', [
            'form' => $form,
            'project' => $project,
            'template' => $template,
        ]);
    }
}
