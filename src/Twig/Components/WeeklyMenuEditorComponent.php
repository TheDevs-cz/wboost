<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenuDietVersion;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenuMealVariant;
use WBoost\Web\Message\WeeklyMenu\DeleteWeeklyMenuDietVersion;
use WBoost\Web\Message\WeeklyMenu\DeleteWeeklyMenuMealVariant;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenuDietVersion;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenuMealVariant;
use WBoost\Web\Message\WeeklyMenu\SortWeeklyMenuDietVersions;
use WBoost\Web\Message\WeeklyMenu\SortWeeklyMenuMealVariants;
use WBoost\Web\Services\ProvideIdentity;

#[AsLiveComponent('WeeklyMenuEditor')]
final class WeeklyMenuEditorComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|WeeklyMenu $menu = null;

    #[LiveProp(writable: true)]
    public int $activeDay = 1;

    #[LiveProp(writable: true)]
    public bool $editingHeader = false;

    #[LiveProp(writable: true)]
    public string $headerName = '';

    #[LiveProp(writable: true)]
    public string $headerValidFrom = '';

    #[LiveProp(writable: true)]
    public string $headerValidTo = '';

    #[LiveProp(writable: true)]
    public string $headerCreatedBy = '';

    #[LiveProp(writable: true)]
    public string $headerApprovedBy = '';

    #[LiveProp]
    public null|string $lastSaved = null;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private EntityManagerInterface $em,
    ) {
    }

    public function getActiveMenuDay(): null|WeeklyMenuDay
    {
        return $this->menu?->day($this->activeDay);
    }

    #[LiveAction]
    public function switchDay(#[LiveArg] int $day): void
    {
        $this->activeDay = $day;

        // Force refresh entity to get fresh data from database
        if ($this->menu !== null) {
            $this->em->refresh($this->menu);
        }
    }

    #[LiveAction]
    public function toggleHeaderEdit(): void
    {
        $this->editingHeader = !$this->editingHeader;

        if ($this->editingHeader && $this->menu !== null) {
            $this->headerName = $this->menu->name;
            $this->headerValidFrom = $this->menu->validFrom->format('Y-m-d');
            $this->headerValidTo = $this->menu->validTo->format('Y-m-d');
            $this->headerCreatedBy = $this->menu->createdBy ?? '';
            $this->headerApprovedBy = $this->menu->approvedBy ?? '';
        }
    }

    #[LiveAction]
    public function saveHeader(): void
    {
        if ($this->menu === null) {
            return;
        }

        if ($this->editingHeader) {
            $this->bus->dispatch(new EditWeeklyMenu(
                $this->menu->id,
                $this->headerName,
                new \DateTimeImmutable($this->headerValidFrom),
                new \DateTimeImmutable($this->headerValidTo),
                $this->headerCreatedBy === '' ? null : $this->headerCreatedBy,
                $this->headerApprovedBy === '' ? null : $this->headerApprovedBy,
            ));

            $this->em->refresh($this->menu);
            $this->editingHeader = false;
        }

        $this->lastSaved = (new \DateTime())->format('H:i:s');
    }

    #[LiveAction]
    public function addVariant(#[LiveArg] string $mealId): void
    {
        $this->bus->dispatch(new AddWeeklyMenuMealVariant(
            Uuid::fromString($mealId),
            $this->provideIdentity->next(),
        ));

        $this->refreshMenu();
    }

    #[LiveAction]
    public function removeVariant(#[LiveArg] string $variantId): void
    {
        $this->bus->dispatch(new DeleteWeeklyMenuMealVariant(
            Uuid::fromString($variantId),
        ));

        $this->refreshMenu();
    }

    #[LiveAction]
    public function addDietVersion(#[LiveArg] string $variantId): void
    {
        $this->bus->dispatch(new AddWeeklyMenuDietVersion(
            Uuid::fromString($variantId),
            $this->provideIdentity->next(),
        ));

        $this->refreshMenu();
    }

    #[LiveAction]
    public function removeDietVersion(#[LiveArg] string $dietVersionId): void
    {
        $this->bus->dispatch(new DeleteWeeklyMenuDietVersion(
            Uuid::fromString($dietVersionId),
        ));

        $this->refreshMenu();
    }

    #[LiveAction]
    public function updateVariantName(
        #[LiveArg] string $variantId,
        #[LiveArg] string $name,
    ): void {
        $this->bus->dispatch(new EditWeeklyMenuMealVariant(
            Uuid::fromString($variantId),
            $name === '' ? null : $name,
        ));

        $this->refreshMenu();
    }

    #[LiveAction]
    public function updateDietVersion(
        #[LiveArg] string $dietVersionId,
        #[LiveArg] string $dietCodes,
        #[LiveArg] string $items,
    ): void {
        $this->bus->dispatch(new EditWeeklyMenuDietVersion(
            Uuid::fromString($dietVersionId),
            $dietCodes === '' ? null : $dietCodes,
            $items === '' ? null : $items,
        ));

        $this->refreshMenu();
    }

    /**
     * @param array<string> $sorted
     */
    #[LiveAction]
    public function sortVariants(#[LiveArg] string $mealId, #[LiveArg] array $sorted): void
    {
        $sortedIds = array_map(fn($id) => Uuid::fromString($id), $sorted);

        $this->bus->dispatch(new SortWeeklyMenuMealVariants(
            Uuid::fromString($mealId),
            $sortedIds,
        ));

        $this->refreshMenu();
    }

    /**
     * @param array<string> $sorted
     */
    #[LiveAction]
    public function sortDietVersions(#[LiveArg] string $variantId, #[LiveArg] array $sorted): void
    {
        $sortedIds = array_map(fn($id) => Uuid::fromString($id), $sorted);

        $this->bus->dispatch(new SortWeeklyMenuDietVersions(
            Uuid::fromString($variantId),
            $sortedIds,
        ));

        $this->refreshMenu();
    }

    private function refreshMenu(): void
    {
        if ($this->menu !== null) {
            $this->em->refresh($this->menu);
        }

        $this->lastSaved = (new \DateTime())->format('H:i:s');
    }
}
