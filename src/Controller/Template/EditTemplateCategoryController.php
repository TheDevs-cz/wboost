<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\FormData\TemplateCategoryFormData;
use WBoost\Web\FormType\TemplateCategoryFormType;
use WBoost\Web\Message\Template\EditTemplateCategory;
use WBoost\Web\Services\Security\TemplateCategoryVoter;

final class EditTemplateCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-category/{categoryId}/edit', name: 'edit_template_category')]
    #[IsGranted(TemplateCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        TemplateCategory $category,
        Request $request,
    ): Response {
        $project = $category->project;
        $data = new TemplateCategoryFormData();
        $data->name = $category->name;

        $form = $this->createForm(TemplateCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditTemplateCategory(
                    $category->id,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Kategorie upravena!');

            return $this->redirectToRoute('template_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_template_category.html.twig', [
            'form' => $form,
            'project' => $project,
            'category' => $category,
        ]);
    }
}
