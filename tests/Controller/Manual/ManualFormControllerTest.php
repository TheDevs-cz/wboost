<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ManualType;

/**
 * Content pages P4: the add / edit manual form no longer uploads an intro
 * picture (the intro page holds it); it points at the intro page instead.
 *
 * @covers \WBoost\Web\Controller\Manual\AddManualController
 * @covers \WBoost\Web\Controller\Manual\EditManualController
 */
final class ManualFormControllerTest extends WebTestCase
{
    public function testAddingAManualHasNoIntroUpload(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/add-manual');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[type="file"]'));
        self::assertStringContainsString('Úvodní stránku', $crawler->filter('.man-formhint')->text());

        $form = $crawler->filter('form[name="manual_form"]')->form();
        $form['manual_form[name]'] = 'Nový manuál P4';
        $form['manual_form[type]'] = ManualType::Brand->value;
        $browser->submit($form);
        self::assertResponseRedirects();

        $manual = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Manual::class)->findOneBy(['name' => 'Nový manuál P4']);
        self::assertInstanceOf(Manual::class, $manual);
        self::assertSame(ManualType::Brand, $manual->type);
    }

    public function testEditingAManualLinksToTheIntroPage(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/edit-manual/' . TestDataFixture::MANUAL_1_ID);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[type="file"]'));
        self::assertSame('/manual/' . TestDataFixture::MANUAL_1_ID . '/uvodni-stranka', $crawler->filter('.man-formhint a')->attr('href'));

        $form = $crawler->filter('form[name="manual_form"]')->form();
        $form['manual_form[name]'] = 'Přejmenovaný';
        $browser->submit($form);
        self::assertResponseRedirects('/manual/' . TestDataFixture::MANUAL_1_ID);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertInstanceOf(Manual::class, $manual);
        self::assertSame('Přejmenovaný', $manual->name);
    }
}
