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
use WBoost\Web\FormData\DuplicateWeeklyMenuFormData;
use WBoost\Web\FormType\DuplicateWeeklyMenuFormType;
use WBoost\Web\Message\WeeklyMenu\CopyWeeklyMenu;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class DuplicateWeeklyMenuController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/duplicate', name: 'duplicate_weekly_menu')]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
    ): Response {
        $data = new DuplicateWeeklyMenuFormData();
        $form = $this->createForm(DuplicateWeeklyMenuFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newId = $this->provideIdentity->next();

            assert($data->validFrom !== null);
            assert($data->validTo !== null);

            $this->bus->dispatch(
                new CopyWeeklyMenu(
                    $menu->id,
                    $newId,
                    $data->validFrom,
                    $data->validTo,
                ),
            );

            $this->addFlash('success', 'Jídelníček byl zduplikovan.');

            return $this->redirectToRoute('edit_weekly_menu', [
                'menuId' => $newId,
            ]);
        }

        return $this->render('duplicate_weekly_menu.html.twig', [
            'form' => $form,
            'menu' => $menu,
            'project' => $menu->project,
        ]);
    }
}
