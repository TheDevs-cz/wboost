<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\RegistrationRequestStatus;

final class DismissRegistrationRequestControllerTest extends WebTestCase
{
    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/registration-requests/' . TestDataFixture::REGISTRATION_REQUEST_PENDING_ID . '/dismiss');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDismissForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/registration-requests/' . TestDataFixture::REGISTRATION_REQUEST_PENDING_ID . '/dismiss');

        $this->assertResponseRedirects('/admin/registration-requests');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $request = self::getContainer()->get(RegistrationRequestRepository::class)
            ->getById(Uuid::fromString(TestDataFixture::REGISTRATION_REQUEST_PENDING_ID));
        self::assertSame(RegistrationRequestStatus::Dismissed, $request->status);
    }
}
