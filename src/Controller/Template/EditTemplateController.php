<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Template;
use WBoost\Web\FormData\TemplateFormData;
use WBoost\Web\FormType\TemplateFormType;
use WBoost\Web\Message\Template\EditTemplate;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Services\Security\TemplateVoter;

final class EditTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private GetTemplateCategories $getTemplateCategories,
    ) {
    }

    #[Route(path: '/template/{templateId}/edit', name: 'edit_template')]
    #[IsGranted(TemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        Template $template,
        Request $request,
    ): Response {
        $project = $template->project;
        $categories = $this->getTemplateCategories->allForProject($project->id);
        $data = new TemplateFormData();
        $data->name = $template->name;
        $data->category = $template->category?->id->toString();
        $form = $this->createForm(TemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new EditTemplate(
                    $template->id,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            $this->addFlash('success', 'Šablona upravena!');

            return $this->redirectToRoute('templates', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_template.html.twig', [
            'form' => $form,
            'project' => $project,
            'template' => $template,
        ]);
    }
}
