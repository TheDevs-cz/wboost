<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

final class AdminUsageControllerTest extends WebTestCase
{
    public function testAnonymousRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/usage');

        $this->assertResponseRedirects('/login');
    }

    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/usage');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRendersEmptyStateForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Zatím nebyly zaznamenány žádné exporty');
    }

    public function testRendersSeededUsageForAdmin(): void
    {
        $browser = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ExportEvent(
            Uuid::uuid7(),
            new DateTimeImmutable('2026-06-15 10:00:00'),
            ExportedTemplateType::SocialNetwork,
            ExportChannel::Web,
            Uuid::uuid4(),
            'Letní kampaň',
            Uuid::uuid4(),
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            'Projekt Alfa',
            Uuid::fromString(TestDataFixture::USER_1_ID),
            TestDataFixture::USER_1_EMAIL,
            null,
        ));
        $entityManager->flush();

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', TestDataFixture::USER_1_EMAIL);
        $this->assertSelectorTextContains('body', 'Projekt Alfa');
        $this->assertSelectorExists('#usage-chart');
    }
}
