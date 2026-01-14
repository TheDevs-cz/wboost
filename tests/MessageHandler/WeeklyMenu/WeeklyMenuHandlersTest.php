<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\WeeklyMenu;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\DeleteWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenu;
use WBoost\Web\MessageHandler\WeeklyMenu\AddWeeklyMenuHandler;
use WBoost\Web\MessageHandler\WeeklyMenu\DeleteWeeklyMenuHandler;
use WBoost\Web\MessageHandler\WeeklyMenu\EditWeeklyMenuHandler;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class WeeklyMenuHandlersTest extends KernelTestCase
{
    // ==================== AddWeeklyMenuHandler Tests ====================

    public function testAddWeeklyMenuCreatesMenuWithDays(): void
    {
        $handler = $this->getHandler(AddWeeklyMenuHandler::class);
        $menuId = Uuid::uuid4();

        $handler(new AddWeeklyMenu(
            projectId: Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            menuId: $menuId,
            name: 'New Test Menu',
            validFrom: new DateTimeImmutable('2024-02-01'),
            validTo: new DateTimeImmutable('2024-02-07'),
            createdBy: 'Test Creator',
            approvedBy: 'Test Approver',
        ));

        $repository = self::getContainer()->get(WeeklyMenuRepository::class);
        $menu = $repository->get($menuId);

        self::assertEquals('New Test Menu', $menu->name);
        self::assertEquals('Test Creator', $menu->createdBy);
        self::assertEquals('Test Approver', $menu->approvedBy);
        self::assertEquals('2024-02-01', $menu->validFrom->format('Y-m-d'));
        self::assertEquals('2024-02-07', $menu->validTo->format('Y-m-d'));
        self::assertCount(7, $menu->days());
    }

    public function testAddWeeklyMenuThrowsExceptionWhenProjectNotFound(): void
    {
        $this->expectException(ProjectNotFound::class);

        $handler = $this->getHandler(AddWeeklyMenuHandler::class);

        $handler(new AddWeeklyMenu(
            projectId: Uuid::uuid4(), // Non-existent project
            menuId: Uuid::uuid4(),
            name: 'Test Menu',
            validFrom: new DateTimeImmutable('2024-01-01'),
            validTo: new DateTimeImmutable('2024-01-07'),
        ));
    }

    // ==================== EditWeeklyMenuHandler Tests ====================

    public function testEditWeeklyMenuUpdatesNameAndDates(): void
    {
        $handler = $this->getHandler(EditWeeklyMenuHandler::class);

        $handler(new EditWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_1_ID),
            name: 'Updated Menu Name',
            validFrom: new DateTimeImmutable('2024-03-01'),
            validTo: new DateTimeImmutable('2024-03-07'),
            createdBy: 'Updated Creator',
            approvedBy: 'Updated Approver',
        ));

        $repository = self::getContainer()->get(WeeklyMenuRepository::class);
        $menu = $repository->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_1_ID));

        self::assertEquals('Updated Menu Name', $menu->name);
        self::assertEquals('Updated Creator', $menu->createdBy);
        self::assertEquals('Updated Approver', $menu->approvedBy);
        self::assertEquals('2024-03-01', $menu->validFrom->format('Y-m-d'));
        self::assertEquals('2024-03-07', $menu->validTo->format('Y-m-d'));
        self::assertNotNull($menu->updatedAt);
    }

    public function testEditWeeklyMenuThrowsExceptionWhenMenuNotFound(): void
    {
        $this->expectException(WeeklyMenuNotFound::class);

        $handler = $this->getHandler(EditWeeklyMenuHandler::class);

        $handler(new EditWeeklyMenu(
            menuId: Uuid::uuid4(), // Non-existent menu
            name: 'Test',
            validFrom: new DateTimeImmutable('2024-01-01'),
            validTo: new DateTimeImmutable('2024-01-07'),
        ));
    }

    // ==================== DeleteWeeklyMenuHandler Tests ====================

    public function testDeleteWeeklyMenuRemovesMenu(): void
    {
        // First create a menu to delete
        $addHandler = $this->getHandler(AddWeeklyMenuHandler::class);
        $menuId = Uuid::uuid4();

        $addHandler(new AddWeeklyMenu(
            projectId: Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            menuId: $menuId,
            name: 'Menu to Delete',
            validFrom: new DateTimeImmutable('2024-01-01'),
            validTo: new DateTimeImmutable('2024-01-07'),
        ));

        // Now delete it
        $deleteHandler = $this->getHandler(DeleteWeeklyMenuHandler::class);
        $deleteHandler(new DeleteWeeklyMenu(menuId: $menuId));

        // Verify it's deleted
        $this->expectException(WeeklyMenuNotFound::class);
        $repository = self::getContainer()->get(WeeklyMenuRepository::class);
        $repository->get($menuId);
    }

    public function testDeleteWeeklyMenuThrowsExceptionWhenMenuNotFound(): void
    {
        $this->expectException(WeeklyMenuNotFound::class);

        $handler = $this->getHandler(DeleteWeeklyMenuHandler::class);
        $handler(new DeleteWeeklyMenu(menuId: Uuid::uuid4()));
    }

    // ==================== Helper Methods ====================

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function getHandler(string $class): object
    {
        $handler = self::getContainer()->get($class);
        assert($handler instanceof $class);

        return $handler;
    }
}
