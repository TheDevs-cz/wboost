<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\Manual\EditManualLogoSlotWidthController
 */
final class EditManualLogoSlotWidthControllerTest extends WebTestCase
{
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';
    private const string SAVE_URL = '/manual/' . TestDataFixture::MANUAL_1_ID . '/sirka-loga';
    private const string SLOT = 'horizontal_backgrounds.horizontal.darkBackground';

    public function testSavesInPlaceAndAnswersJson(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $this->post($browser, self::SLOT, '35');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['slotWidth' => 35, 'displayWidth' => 35],
            json_decode((string) $browser->getResponse()->getContent(), true),
        );
        self::assertSame(35, $this->manual()->logoSlotWidth(self::SLOT));
    }

    public function testEmptyWidthClearsTheCard(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $this->post($browser, self::SLOT, '35');
        $this->post($browser, self::SLOT, '');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['slotWidth' => null, 'displayWidth' => null],
            json_decode((string) $browser->getResponse()->getContent(), true),
        );
        self::assertSame([], $this->manual()->logoSlotWidths);
    }

    public function testRejectsAnUnknownSlot(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        foreach (['anything', 'basic_logos.horizontal', 'nope.horizontal.base', 'basic_logos.nope.base', 'basic_logos.horizontal.nope'] as $slot) {
            $this->post($browser, $slot, '35');
            self::assertResponseStatusCodeSame(400, $slot);
        }

        self::assertSame([], $this->manual()->logoSlotWidths);
    }

    public function testRejectsAnOutOfRangeWidth(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        foreach (['101', '-5', 'abc', '12.5'] as $width) {
            $this->post($browser, self::SLOT, $width);
            self::assertResponseStatusCodeSame(400, $width);
        }

        self::assertSame([], $this->manual()->logoSlotWidths);
    }

    public function testRejectsAMissingCsrfToken(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('POST', self::SAVE_URL, ['slot' => self::SLOT, 'displayWidth' => '35']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->manual()->logoSlotWidths);
    }

    public function testAnotherUsersManualIsForbidden(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        $browser->request('POST', self::SAVE_URL, ['slot' => self::SLOT, 'displayWidth' => '35']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testReaderGetsNoWidthBar(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PREVIEW_URL);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="manual-logo-width"]'));
        self::assertCount(0, $crawler->filter('.manual-logo-width-bar'));
    }

    private function post(KernelBrowser $browser, string $slot, string $width): void
    {
        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('[data-manual-logo-width-csrf-value]')->attr('data-manual-logo-width-csrf-value');
        self::assertIsString($token);

        $browser->request('POST', self::SAVE_URL, [
            '_token' => $token,
            'slot' => $slot,
            'displayWidth' => $width,
        ], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function manual(): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        assert($manual instanceof Manual);

        return $manual;
    }
}
