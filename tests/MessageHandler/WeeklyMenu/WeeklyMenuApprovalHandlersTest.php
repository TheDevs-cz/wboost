<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\WeeklyMenu;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Exceptions\InvalidApprovalHash;
use WBoost\Web\Message\WeeklyMenu\AddWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\ApproveWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\DenyWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\EditWeeklyMenu;
use WBoost\Web\Message\WeeklyMenu\RequestWeeklyMenuApproval;
use WBoost\Web\MessageHandler\WeeklyMenu\AddWeeklyMenuHandler;
use WBoost\Web\MessageHandler\WeeklyMenu\ApproveWeeklyMenuHandler;
use WBoost\Web\MessageHandler\WeeklyMenu\DenyWeeklyMenuHandler;
use WBoost\Web\MessageHandler\WeeklyMenu\EditWeeklyMenuHandler;
use WBoost\Web\MessageHandler\WeeklyMenu\RequestWeeklyMenuApprovalHandler;
use WBoost\Web\Repository\WeeklyMenuApprovalAuditLogRepository;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\WeeklyMenuApprovalStatus;

final class WeeklyMenuApprovalHandlersTest extends KernelTestCase
{
    public function testRequestApprovalSetsStatusToPending(): void
    {
        $menuId = $this->createMenuWithApprovalEmail();

        $handler = $this->getHandler(RequestWeeklyMenuApprovalHandler::class);
        $handler(new RequestWeeklyMenuApproval(
            menuId: $menuId,
            requestedByEmail: 'requester@test.cz',
        ));

        $menu = $this->getRepository()->get($menuId);

        self::assertSame(WeeklyMenuApprovalStatus::Pending, $menu->approvalStatus);
        self::assertNotNull($menu->approvalHash);
        self::assertSame('requester@test.cz', $menu->requestedByEmail);
    }

    public function testRequestApprovalFailsWithoutApprovalEmail(): void
    {
        $this->expectException(\LogicException::class);

        $menuId = Uuid::uuid4();
        $addHandler = $this->getHandler(AddWeeklyMenuHandler::class);
        $addHandler(new AddWeeklyMenu(
            projectId: Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            menuId: $menuId,
            name: 'No Email Menu',
            validFrom: new DateTimeImmutable('2024-03-01'),
            validTo: new DateTimeImmutable('2024-03-07'),
        ));

        $handler = $this->getHandler(RequestWeeklyMenuApprovalHandler::class);
        $handler(new RequestWeeklyMenuApproval(
            menuId: $menuId,
            requestedByEmail: 'requester@test.cz',
        ));
    }

    public function testApproveMenuSetsStatusToApproved(): void
    {
        $handler = $this->getHandler(ApproveWeeklyMenuHandler::class);

        $handler(new ApproveWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
        ));

        $menu = $this->getRepository()->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));

        self::assertSame(WeeklyMenuApprovalStatus::Approved, $menu->approvalStatus);
        self::assertNotNull($menu->approvalRespondedAt);
    }

    public function testApproveMenuStoresComment(): void
    {
        $handler = $this->getHandler(ApproveWeeklyMenuHandler::class);

        $handler(new ApproveWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
            comment: 'Vypadá skvěle!',
        ));

        $menu = $this->getRepository()->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));

        self::assertSame('Vypadá skvěle!', $menu->approvalComment);
    }

    public function testApproveMenuFailsWithInvalidHash(): void
    {
        $this->expectException(InvalidApprovalHash::class);

        $handler = $this->getHandler(ApproveWeeklyMenuHandler::class);

        $handler(new ApproveWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: 'invalid_hash_value',
        ));
    }

    public function testApproveMenuFailsWhenNotPending(): void
    {
        // First approve the menu
        $handler = $this->getHandler(ApproveWeeklyMenuHandler::class);
        $handler(new ApproveWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
        ));

        // Now try to approve again — should fail because status is no longer Pending
        $this->expectException(\LogicException::class);
        $handler(new ApproveWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
        ));
    }

    public function testDenyMenuSetsStatusToDenied(): void
    {
        $handler = $this->getHandler(DenyWeeklyMenuHandler::class);

        $handler(new DenyWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
        ));

        $menu = $this->getRepository()->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));

        self::assertSame(WeeklyMenuApprovalStatus::Denied, $menu->approvalStatus);
        self::assertNotNull($menu->approvalRespondedAt);
    }

    public function testDenyMenuStoresComment(): void
    {
        $handler = $this->getHandler(DenyWeeklyMenuHandler::class);

        $handler(new DenyWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
            comment: 'Potřebuje úpravy.',
        ));

        $menu = $this->getRepository()->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));

        self::assertSame('Potřebuje úpravy.', $menu->approvalComment);
    }

    public function testAuditLogCreatedOnRequest(): void
    {
        $menuId = $this->createMenuWithApprovalEmail();

        $handler = $this->getHandler(RequestWeeklyMenuApprovalHandler::class);
        $handler(new RequestWeeklyMenuApproval(
            menuId: $menuId,
            requestedByEmail: 'requester@test.cz',
        ));

        $auditLogs = $this->getAuditLogRepository()->findByMenu($menuId);
        self::assertCount(1, $auditLogs);
        self::assertStringContainsString('Odeslána žádost o schválení', $auditLogs[0]->event);
        self::assertSame('requester@test.cz', $auditLogs[0]->performedBy);
    }

    public function testAuditLogCreatedOnApproval(): void
    {
        $handler = $this->getHandler(ApproveWeeklyMenuHandler::class);

        $handler(new ApproveWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
            comment: 'OK',
        ));

        $auditLogs = $this->getAuditLogRepository()->findByMenu(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));
        self::assertCount(1, $auditLogs);
        self::assertStringContainsString('Schváleno', $auditLogs[0]->event);
        self::assertSame('approver@test.cz', $auditLogs[0]->performedBy);
        self::assertSame('OK', $auditLogs[0]->comment);
    }

    public function testAuditLogCreatedOnDenial(): void
    {
        $handler = $this->getHandler(DenyWeeklyMenuHandler::class);

        $handler(new DenyWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
            comment: 'Neschvaluji',
        ));

        $auditLogs = $this->getAuditLogRepository()->findByMenu(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));
        self::assertCount(1, $auditLogs);
        self::assertStringContainsString('Zamítnuto', $auditLogs[0]->event);
        self::assertSame('approver@test.cz', $auditLogs[0]->performedBy);
        self::assertSame('Neschvaluji', $auditLogs[0]->comment);
    }

    public function testEditMenuResetsApprovalStatus(): void
    {
        // Approve the menu first
        $approveHandler = $this->getHandler(ApproveWeeklyMenuHandler::class);
        $approveHandler(new ApproveWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            hash: TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH,
        ));

        $menu = $this->getRepository()->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));
        self::assertSame(WeeklyMenuApprovalStatus::Approved, $menu->approvalStatus);

        // Now edit the menu
        $editHandler = $this->getHandler(EditWeeklyMenuHandler::class);
        $editHandler(new EditWeeklyMenu(
            menuId: Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID),
            name: 'Updated Name',
            validFrom: new DateTimeImmutable('2024-02-01'),
            validTo: new DateTimeImmutable('2024-02-07'),
            approvalEmail: 'approver@test.cz',
        ));

        $menu = $this->getRepository()->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_2_ID));
        self::assertSame(WeeklyMenuApprovalStatus::NotRequested, $menu->approvalStatus);
        self::assertNull($menu->approvalHash);
        self::assertNull($menu->approvalRespondedAt);
        self::assertNull($menu->approvalComment);
    }

    // ==================== Helper Methods ====================

    private function createMenuWithApprovalEmail(): \Ramsey\Uuid\UuidInterface
    {
        $menuId = Uuid::uuid4();
        $addHandler = $this->getHandler(AddWeeklyMenuHandler::class);
        $addHandler(new AddWeeklyMenu(
            projectId: Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            menuId: $menuId,
            name: 'Menu With Email',
            validFrom: new DateTimeImmutable('2024-03-01'),
            validTo: new DateTimeImmutable('2024-03-07'),
            approvalEmail: 'approver@test.cz',
        ));

        return $menuId;
    }

    private function getRepository(): WeeklyMenuRepository
    {
        return self::getContainer()->get(WeeklyMenuRepository::class);
    }

    private function getAuditLogRepository(): WeeklyMenuApprovalAuditLogRepository
    {
        return self::getContainer()->get(WeeklyMenuApprovalAuditLogRepository::class);
    }

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
