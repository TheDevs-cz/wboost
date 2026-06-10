<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerCategory;
use WBoost\Web\FormData\FlyerCategoryFormData;
use WBoost\Web\FormType\FlyerCategoryFormType;
use WBoost\Web\Message\Flyer\EditFlyerCategory;
use WBoost\Web\Services\Security\FlyerCategoryVoter;

final class EditFlyerCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/flyer-category/{categoryId}/edit', name: 'edit_flyer_category')]
    #[IsGranted(FlyerCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        FlyerCategory $category,
        Request $request,
    ): Response {
        $project = $category->project;
        $data = new FlyerCategoryFormData();
        $data->name = $category->name;

        $form = $this->createForm(FlyerCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditFlyerCategory(
                    $category->id,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Kategorie upravena!');

            return $this->redirectToRoute('flyer_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_flyer_category.html.twig', [
            'form' => $form,
            'project' => $project,
            'category' => $category,
        ]);
    }
}
