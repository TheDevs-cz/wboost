<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\Template\TemplateVariantPrintSettingsController
 * @covers \WBoost\Web\MessageHandler\Template\EditTemplateVariantPrintSettingsHandler
 */
final class TemplateVariantPrintSettingsControllerTest extends WebTestCase
{
    private function url(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/print-settings';
    }

    public function testSavesBleedAndCropMarksWithoutTouchingTheSize(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="template_variant_print_settings_form[bleed]"]');

        $form = $crawler->selectButton('Uložit')->form([
            'template_variant_print_settings_form[bleed]' => '5',
            'template_variant_print_settings_form[cropMarks]' => '1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/template/' . TestDataFixture::CUSTOM_TEMPLATE_1_ID . '/variants');

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
        self::assertSame(5.0, $variant->dimension->bleedMm);
        self::assertTrue($variant->dimension->cropMarks);
        self::assertSame(2480, $variant->dimension->width());
    }

    public function testRejectsAnAbsurdBleed(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->url());
        $client->submit($crawler->selectButton('Uložit')->form([
            'template_variant_print_settings_form[bleed]' => '50',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUserWithoutEditRightsIsDenied(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }
}
