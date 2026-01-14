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
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\FormData\WeeklyMenuFormData;
use WBoost\Web\FormType\WeeklyMenuFormType;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenu;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class EditWeeklyMenuController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/edit-weekly-menu/{menuId}', name: 'edit_weekly_menu')]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        $data = new WeeklyMenuFormData();
        $data->name = $menu->name;
        $data->validFrom = $menu->validFrom;
        $data->validTo = $menu->validTo;
        $data->createdBy = $menu->createdBy;
        $data->approvedBy = $menu->approvedBy;

        $form = $this->createForm(WeeklyMenuFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditWeeklyMenu(
                    $menu->id,
                    $data->name,
                    $data->validFrom,
                    $data->validTo,
                    $data->createdBy,
                    $data->approvedBy,
                ),
            );

            $this->addFlash('success', 'Jídelníček byl upraven.');

            return $this->redirectToRoute('weekly_menus', [
                'projectId' => $menu->project->id,
            ]);
        }

        return $this->render('edit_weekly_menu.html.twig', [
            'form' => $form,
            'menu' => $menu,
            'project' => $menu->project,
        ]);
    }
}
