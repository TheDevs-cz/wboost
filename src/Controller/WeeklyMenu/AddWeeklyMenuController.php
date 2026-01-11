<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\WeeklyMenuFormData;
use WBoost\Web\FormType\WeeklyMenuFormType;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenu;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddWeeklyMenuController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/project/{projectId}/add-weekly-menu', name: 'add_weekly_menu')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $data = new WeeklyMenuFormData();
        $form = $this->createForm(WeeklyMenuFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $menuId = $this->provideIdentity->next();

            assert($data->validFrom !== null);
            assert($data->validTo !== null);

            $this->bus->dispatch(
                new AddWeeklyMenu(
                    $project->id,
                    $menuId,
                    $data->name,
                    $data->validFrom,
                    $data->validTo,
                    $data->createdBy,
                    $data->approvedBy,
                ),
            );

            $this->addFlash('success', 'Jídelníček byl vytvořen.');

            return $this->redirectToRoute('edit_weekly_menu', [
                'menuId' => $menuId,
            ]);
        }

        return $this->render('add_weekly_menu.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
