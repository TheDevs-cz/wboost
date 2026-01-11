<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\WeeklyMenu;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuMeal;
use WBoost\Web\Message\WeeklyMenu\SortWeeklyMenuMealVariants;
use WBoost\Web\Services\Security\WeeklyMenuVoter;

final class SortWeeklyMenuVariantsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/weekly-menu/{menuId}/sort-variants/{mealId}', name: 'sort_weekly_menu_variants', methods: ['POST'])]
    #[IsGranted(WeeklyMenuVoter::EDIT, 'menu')]
    public function __invoke(
        #[MapEntity(id: 'menuId')]
        WeeklyMenu $menu,
        #[MapEntity(id: 'mealId')]
        WeeklyMenuMeal $meal,
        Request $request,
    ): JsonResponse {
        /** @var array{sorted?: array<string>} $data */
        $data = json_decode($request->getContent(), true);
        $sorted = $data['sorted'] ?? [];

        $sortedIds = array_map(
            fn(string $id) => Uuid::fromString($id),
            $sorted,
        );

        $this->bus->dispatch(
            new SortWeeklyMenuMealVariants($meal->id, $sortedIds),
        );

        return new JsonResponse(['status' => 'success']);
    }
}
