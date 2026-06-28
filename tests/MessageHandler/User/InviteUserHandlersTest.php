<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\User;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\UserAlreadyRegistered;
use WBoost\Web\Message\User\EditUser;
use WBoost\Web\Message\User\InviteUser;
use WBoost\Web\Message\User\ResendInvitation;
use WBoost\Web\MessageHandler\User\EditUserHandler;
use WBoost\Web\MessageHandler\User\InviteUserHandler;
use WBoost\Web\MessageHandler\User\ResendInvitationHandler;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\RegistrationRequestStatus;
use WBoost\Web\Value\SharingLevel;

final class InviteUserHandlersTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    public function testInviteCreatesUnconfirmedUserWithRolesTokenAndPreShare(): void
    {
        $handler = self::getContainer()->get(InviteUserHandler::class);
        $handler(new InviteUser(
            'fresh-invitee@test.cz',
            'Fresh Invitee',
            [User::ROLE_DESIGNER],
            [TestDataFixture::PROJECT_2_ID],
            TestDataFixture::ADMIN_USER_ID,
        ));
        $this->flushAndClear();

        $user = self::getContainer()->get(UserRepository::class)->findByEmailOrNull('fresh-invitee@test.cz');
        self::assertNotNull($user);
        self::assertFalse($user->confirmed);
        self::assertSame('', $user->password);
        self::assertSame('Fresh Invitee', $user->name);
        self::assertContains(User::ROLE_DESIGNER, $user->getRoles());

        // Pre-share applied.
        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_2_ID));
        self::assertSame(SharingLevel::Read, $project->getUserSharingLevel($user));

        // Invitation e-mail sent.
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'fresh-invitee@test.cz');
    }

    public function testReInviteReusesPendingUser(): void
    {
        $handler = self::getContainer()->get(InviteUserHandler::class);
        $handler(new InviteUser(
            TestDataFixture::INVITED_USER_EMAIL,
            'Renamed Invitee',
            [User::ROLE_ADMIN],
            [],
            TestDataFixture::ADMIN_USER_ID,
        ));
        $this->flushAndClear();

        $user = self::getContainer()->get(UserRepository::class)->findByEmailOrNull(TestDataFixture::INVITED_USER_EMAIL);
        self::assertNotNull($user);
        // Same row reused (still the fixture id), refreshed metadata, still pending.
        self::assertSame(TestDataFixture::INVITED_USER_ID, $user->id->toString());
        self::assertFalse($user->confirmed);
        self::assertSame('Renamed Invitee', $user->name);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());

        self::assertEmailCount(1);
    }

    public function testInviteRejectsConfirmedDuplicate(): void
    {
        $this->expectException(UserAlreadyRegistered::class);

        $handler = self::getContainer()->get(InviteUserHandler::class);
        $handler(new InviteUser(
            TestDataFixture::USER_1_EMAIL,
            null,
            [],
            [],
            TestDataFixture::ADMIN_USER_ID,
        ));
    }

    public function testEditUserUpdatesNameAndRoles(): void
    {
        $handler = self::getContainer()->get(EditUserHandler::class);
        $handler(new EditUser(TestDataFixture::USER_2_ID, 'Edited Name', [User::ROLE_ADMIN]));
        $this->flushAndClear();

        $user = self::getContainer()->get(UserRepository::class)->getById(Uuid::fromString(TestDataFixture::USER_2_ID));
        self::assertSame('Edited Name', $user->name);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
    }

    public function testResendInvitationSendsEmailForPendingUser(): void
    {
        $handler = self::getContainer()->get(ResendInvitationHandler::class);
        $handler(new ResendInvitation(TestDataFixture::INVITED_USER_ID));

        self::assertEmailCount(1);
    }

    public function testResendInvitationIsNoOpForConfirmedUser(): void
    {
        $handler = self::getContainer()->get(ResendInvitationHandler::class);
        $handler(new ResendInvitation(TestDataFixture::USER_1_ID));

        self::assertEmailCount(0);
    }

    public function testInvitingClosesMatchingPendingRegistrationRequest(): void
    {
        $handler = self::getContainer()->get(InviteUserHandler::class);
        $handler(new InviteUser(
            TestDataFixture::REGISTRATION_REQUEST_PENDING_EMAIL,
            null,
            [User::ROLE_DESIGNER],
            [],
            TestDataFixture::ADMIN_USER_ID,
        ));
        $this->flushAndClear();

        $repository = self::getContainer()->get(RegistrationRequestRepository::class);
        // No longer pending...
        self::assertNull($repository->findPendingByEmail(TestDataFixture::REGISTRATION_REQUEST_PENDING_EMAIL));
        // ...it was marked invited.
        $request = $repository->getById(Uuid::fromString(TestDataFixture::REGISTRATION_REQUEST_PENDING_ID));
        self::assertSame(RegistrationRequestStatus::Invited, $request->status);
    }

    private function flushAndClear(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $em->clear();
    }
}
