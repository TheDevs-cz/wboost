<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerCategory;
use WBoost\Web\Message\Flyer\DeleteFlyerCategory;
use WBoost\Web\Services\Security\FlyerCategoryVoter;

final class DeleteFlyerCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/flyer-category/{categoryId}/delete', name: 'delete_flyer_category')]
    #[IsGranted(FlyerCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        FlyerCategory $category,
    ): Response {
        $project = $category->project;

        $this->bus->dispatch(
            new DeleteFlyerCategory(
                $category->id,
            ),
        );

        $this->addFlash('success', 'Kategorie smazána!');

        return $this->redirectToRoute('flyer_categories', [
            'projectId' => $project->id,
        ]);
    }
}
