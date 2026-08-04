<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Message\Template\DeleteTemplateCategory;
use WBoost\Web\Services\Security\TemplateCategoryVoter;

final class DeleteTemplateCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-category/{categoryId}/delete', name: 'delete_template_category')]
    #[IsGranted(TemplateCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        TemplateCategory $category,
    ): Response {
        $project = $category->project;

        $this->bus->dispatch(
            new DeleteTemplateCategory(
                $category->id,
            ),
        );

        $this->addFlash('success', 'Kategorie smazána!');

        return $this->redirectToRoute('template_categories', [
            'projectId' => $project->id,
        ]);
    }
}
