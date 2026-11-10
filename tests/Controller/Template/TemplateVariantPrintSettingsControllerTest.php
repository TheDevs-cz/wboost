<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateVariant;
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

    private function listUrl(): string
    {
        return '/template/' . TestDataFixture::CUSTOM_TEMPLATE_1_ID . '/variants';
    }

    public function testTheModalSavesBleedAndCropMarksWithoutTouchingTheSize(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->listUrl());
        self::assertResponseIsSuccessful();
        $modal = $crawler->filter('#printSettings' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);
        self::assertCount(1, $modal);
        self::assertStringContainsString('Nastavení tisku', $modal->text());

        $client->request('POST', $this->url(), ['_token' => $modal->filter('input[name="_token"]')->attr('value'), 'bleed' => '5', 'cropMarks' => '1']);
        self::assertResponseRedirects($this->listUrl());

        $variant = $this->variant();
        self::assertSame(5.0, $variant->dimension->bleedMm);
        self::assertTrue($variant->dimension->cropMarks);
        self::assertSame(2480, $variant->dimension->width());
    }

    public function testBleedIsClampedToTheMaximum(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->listUrl());
        $token = $crawler->filter('#printSettings' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . ' input[name="_token"]')->attr('value');

        $client->request('POST', $this->url(), ['_token' => $token, 'bleed' => '50', 'cropMarks' => '0']);

        self::assertResponseRedirects($this->listUrl());
        self::assertSame(10.0, $this->variant()->dimension->bleedMm);
    }

    public function testAnInvalidTokenIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->url(), ['_token' => 'x', 'bleed' => '5']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAGetLandsOnTheVariantsPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->url());

        self::assertResponseRedirects($this->listUrl());
    }

    public function testUserWithoutEditRightsIsDenied(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    public function testScreenFormatsHaveNoPrintSettings(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/print-settings');

        self::assertResponseStatusCodeSame(404);
    }

    private function variant(): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
    }
}
