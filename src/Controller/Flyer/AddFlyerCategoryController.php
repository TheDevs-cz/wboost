<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\FlyerCategoryFormData;
use WBoost\Web\FormType\FlyerCategoryFormType;
use WBoost\Web\Message\Flyer\AddFlyerCategory;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddFlyerCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{id}/add-flyer-category', name: 'add_flyer_category')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $data = new FlyerCategoryFormData();
        $form = $this->createForm(FlyerCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new AddFlyerCategory(
                    $project->id,
                    $data->name,
                ),
            );

            return $this->redirectToRoute('flyer_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('add_flyer_category.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
