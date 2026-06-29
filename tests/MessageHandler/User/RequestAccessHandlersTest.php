<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\User;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use WBoost\Web\Exceptions\AccessAlreadyRequested;
use WBoost\Web\Exceptions\EmailAlreadyRegistered;
use WBoost\Web\Message\User\DismissRegistrationRequest;
use WBoost\Web\Message\User\RequestAccess;
use WBoost\Web\MessageHandler\User\DismissRegistrationRequestHandler;
use WBoost\Web\MessageHandler\User\RequestAccessHandler;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\RegistrationRequestStatus;

final class RequestAccessHandlersTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    public function testNewRequestPersistsAndEmailsAdmins(): void
    {
        $handler = self::getContainer()->get(RequestAccessHandler::class);
        $handler(new RequestAccess('wants-in@test.cz'));
        $this->flushAndClear();

        $request = self::getContainer()->get(RegistrationRequestRepository::class)->findPendingByEmail('wants-in@test.cz');
        self::assertNotNull($request);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'j.mikes@me.com');
        self::assertEmailAddressContains($email, 'To', 'lukas@wantoo.cz');
        self::assertEmailHtmlBodyContains($email, '/admin/registration-requests');
    }

    public function testPendingDuplicateThrows(): void
    {
        $this->expectException(AccessAlreadyRequested::class);

        $handler = self::getContainer()->get(RequestAccessHandler::class);
        $handler(new RequestAccess(TestDataFixture::REGISTRATION_REQUEST_PENDING_EMAIL));
    }

    public function testConfirmedUserThrows(): void
    {
        $this->expectException(EmailAlreadyRegistered::class);

        $handler = self::getContainer()->get(RequestAccessHandler::class);
        $handler(new RequestAccess(TestDataFixture::USER_1_EMAIL));
    }

    public function testCountPendingTracksLifecycle(): void
    {
        $repository = self::getContainer()->get(RegistrationRequestRepository::class);

        // One pending request exists in the fixtures baseline.
        self::assertSame(1, $repository->countPending());

        $request = self::getContainer()->get(RequestAccessHandler::class);
        $request(new RequestAccess('another-one@test.cz'));
        $this->flushAndClear();
        self::assertSame(2, $repository->countPending());

        $dismiss = self::getContainer()->get(DismissRegistrationRequestHandler::class);
        $dismiss(new DismissRegistrationRequest(TestDataFixture::REGISTRATION_REQUEST_PENDING_ID));
        $this->flushAndClear();
        self::assertSame(1, $repository->countPending());
    }

    public function testDismissMarksRequestDismissed(): void
    {
        $handler = self::getContainer()->get(DismissRegistrationRequestHandler::class);
        $handler(new DismissRegistrationRequest(TestDataFixture::REGISTRATION_REQUEST_PENDING_ID));
        $this->flushAndClear();

        $request = self::getContainer()->get(RegistrationRequestRepository::class)
            ->getById(Uuid::fromString(TestDataFixture::REGISTRATION_REQUEST_PENDING_ID));
        self::assertSame(RegistrationRequestStatus::Dismissed, $request->status);
    }

    private function flushAndClear(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $em->clear();
    }
}
