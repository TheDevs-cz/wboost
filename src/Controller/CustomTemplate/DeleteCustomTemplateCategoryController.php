<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateCategory;
use WBoost\Web\Message\CustomTemplate\DeleteCustomTemplateCategory;
use WBoost\Web\Services\Security\CustomTemplateCategoryVoter;

final class DeleteCustomTemplateCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/custom-template-category/{categoryId}/delete', name: 'delete_custom_template_category')]
    #[IsGranted(CustomTemplateCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        CustomTemplateCategory $category,
    ): Response {
        $project = $category->project;

        $this->bus->dispatch(
            new DeleteCustomTemplateCategory(
                $category->id,
            ),
        );

        $this->addFlash('success', 'Kategorie smazána!');

        return $this->redirectToRoute('custom_template_categories', [
            'projectId' => $project->id,
        ]);
    }
}
