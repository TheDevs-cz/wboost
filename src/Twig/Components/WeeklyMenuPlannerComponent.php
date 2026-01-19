<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Message\WeeklyMenuPlanner\AddCourse;
use WBoost\Web\Message\WeeklyMenuPlanner\AddCourseVariant;
use WBoost\Web\Message\WeeklyMenuPlanner\AddDayMealType;
use WBoost\Web\Message\WeeklyMenuPlanner\AddMealToVariant;
use WBoost\Web\Message\WeeklyMenuPlanner\EditCourseVariant;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveCourse;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveCourseVariant;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveDay;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveDayMealType;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveMealFromVariant;
use WBoost\Web\Message\WeeklyMenuPlanner\ReorderCourses;
use WBoost\Web\Message\WeeklyMenuPlanner\ReorderMeals;
use WBoost\Web\Message\WeeklyMenuPlanner\ReorderVariants;
use WBoost\Web\Query\GetDiets;
use WBoost\Web\Query\GetMeals;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\WeeklyMenuMealType;

#[AsLiveComponent('WeeklyMenuPlanner')]
final class WeeklyMenuPlannerComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|WeeklyMenu $menu = null;

    #[LiveProp(writable: true)]
    public null|string $lastSaved = null;

    /** @var array<string> IDs of collapsibles to open after render */
    #[LiveProp]
    public array $openCollapsibles = [];

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private EntityManagerInterface $entityManager,
        readonly private GetMeals $getMeals,
        readonly private GetDiets $getDiets,
    ) {
    }

    #[LiveAction]
    public function addMealType(
        #[LiveArg('dayid')] string $dayId,
        #[LiveArg('mealtype')] string $mealType,
    ): void {
        assert($this->menu !== null);

        $mealTypeId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new AddDayMealType(
                \Ramsey\Uuid\Uuid::fromString($dayId),
                $mealTypeId,
                WeeklyMenuMealType::from($mealType),
            ),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function removeMealType(#[LiveArg('mealtypeid')] string $mealTypeId): void
    {
        $this->bus->dispatch(
            new RemoveDayMealType(\Ramsey\Uuid\Uuid::fromString($mealTypeId)),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function removeDay(#[LiveArg('dayid')] string $dayId): void
    {
        $this->bus->dispatch(
            new RemoveDay(\Ramsey\Uuid\Uuid::fromString($dayId)),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function addCourse(
        #[LiveArg('mealtypeid')] string $mealTypeId,
        #[LiveArg('singlevariantmode')] bool $singleVariantMode = true,
        #[LiveArg('variantcount')] int $variantCount = 1,
    ): void {
        $courseId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new AddCourse(
                \Ramsey\Uuid\Uuid::fromString($mealTypeId),
                $courseId,
                $singleVariantMode,
                $variantCount,
            ),
        );

        // Add the requested number of variants
        for ($i = 0; $i < $variantCount; $i++) {
            $this->bus->dispatch(
                new AddCourseVariant(
                    $courseId,
                    $this->provideIdentity->next(),
                    null,
                ),
            );
        }

        // Mark collapsibles to open after render
        $this->openCollapsibles = [$mealTypeId, $courseId->toString()];

        $this->refreshMenu();
    }

    #[LiveAction]
    public function removeCourse(#[LiveArg('courseid')] string $courseId): void
    {
        $this->bus->dispatch(
            new RemoveCourse(\Ramsey\Uuid\Uuid::fromString($courseId)),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function addVariant(
        #[LiveArg('courseid')] string $courseId,
        #[LiveArg] string $name = '',
    ): void {
        $this->bus->dispatch(
            new AddCourseVariant(
                \Ramsey\Uuid\Uuid::fromString($courseId),
                $this->provideIdentity->next(),
                $name !== '' ? $name : null,
            ),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function editVariant(
        #[LiveArg('variantid')] string $variantId,
        #[LiveArg] string $name = '',
    ): void {
        $this->bus->dispatch(
            new EditCourseVariant(
                \Ramsey\Uuid\Uuid::fromString($variantId),
                $name !== '' ? $name : null,
            ),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function removeVariant(#[LiveArg('variantid')] string $variantId): void
    {
        $this->bus->dispatch(
            new RemoveCourseVariant(\Ramsey\Uuid\Uuid::fromString($variantId)),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function addMeal(
        #[LiveArg('variantid')] string $variantId,
        #[LiveArg('mealid')] string $mealId,
    ): void {
        $this->bus->dispatch(
            new AddMealToVariant(
                \Ramsey\Uuid\Uuid::fromString($variantId),
                $this->provideIdentity->next(),
                \Ramsey\Uuid\Uuid::fromString($mealId),
            ),
        );

        $this->refreshMenu();
    }

    #[LiveAction]
    public function removeMeal(#[LiveArg('variantmealid')] string $variantMealId): void
    {
        $this->bus->dispatch(
            new RemoveMealFromVariant(\Ramsey\Uuid\Uuid::fromString($variantMealId)),
        );

        $this->refreshMenu();
    }

    /**
     * @param array<string> $courseIds
     */
    #[LiveAction]
    public function reorderCourses(
        #[LiveArg('mealtypeid')] string $mealTypeId,
        #[LiveArg('courseids')] array $courseIds,
    ): void {
        $this->bus->dispatch(
            new ReorderCourses(
                \Ramsey\Uuid\Uuid::fromString($mealTypeId),
                array_map(
                    static fn(string $id) => \Ramsey\Uuid\Uuid::fromString($id),
                    $courseIds,
                ),
            ),
        );

        $this->refreshMenu();
    }

    /**
     * @param array<string> $variantIds
     */
    #[LiveAction]
    public function reorderVariants(
        #[LiveArg('courseid')] string $courseId,
        #[LiveArg('variantids')] array $variantIds,
    ): void {
        $this->bus->dispatch(
            new ReorderVariants(
                \Ramsey\Uuid\Uuid::fromString($courseId),
                array_map(
                    static fn(string $id) => \Ramsey\Uuid\Uuid::fromString($id),
                    $variantIds,
                ),
            ),
        );

        $this->refreshMenu();
    }

    /**
     * @param array<string> $mealIds
     */
    #[LiveAction]
    public function reorderMeals(
        #[LiveArg('variantid')] string $variantId,
        #[LiveArg('mealids')] array $mealIds,
    ): void {
        $this->bus->dispatch(
            new ReorderMeals(
                \Ramsey\Uuid\Uuid::fromString($variantId),
                array_map(
                    static fn(string $id) => \Ramsey\Uuid\Uuid::fromString($id),
                    $mealIds,
                ),
            ),
        );

        $this->refreshMenu();
    }

    /**
     * @return array<\WBoost\Web\Entity\Meal>
     */
    public function getMeals(): array
    {
        assert($this->menu !== null);

        return $this->getMeals->allForProject($this->menu->project->id);
    }

    /**
     * @return array<\WBoost\Web\Entity\Diet>
     */
    public function getDiets(): array
    {
        assert($this->menu !== null);

        return $this->getDiets->allForProject($this->menu->project->id);
    }

    /**
     * @return array<WeeklyMenuMealType>
     */
    public function getAvailableMealTypes(): array
    {
        return WeeklyMenuMealType::cases();
    }

    /**
     * @return array<WeeklyMenuMealType>
     */
    public function getAvailableMealTypesForDay(WeeklyMenuDay $day): array
    {
        $usedTypes = [];
        foreach ($day->mealTypes() as $mealType) {
            $usedTypes[] = $mealType->mealType;
        }

        return array_filter(
            WeeklyMenuMealType::cases(),
            fn(WeeklyMenuMealType $type) => !in_array($type, $usedTypes, true),
        );
    }

    private function refreshMenu(): void
    {
        assert($this->menu !== null);

        $this->entityManager->refresh($this->menu);
        $this->lastSaved = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('H:i:s');
    }
}
