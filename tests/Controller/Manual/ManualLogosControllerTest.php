<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\Manual\ManualLogosController
 */
final class ManualLogosControllerTest extends WebTestCase
{
    public function testRendersPerVariantDisplayWidthFields(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/logos');

        $this->assertResponseIsSuccessful();

        $variants = [
            'logoHorizontalDisplayWidth',
            'logoVerticalDisplayWidth',
            'logoHorizontalWithClaimDisplayWidth',
            'logoVerticalWithClaimDisplayWidth',
            'logoSymbolDisplayWidth',
        ];

        foreach ($variants as $field) {
            self::assertCount(
                1,
                $crawler->filter('input[type="number"][name="manual_images_form[' . $field . ']"]'),
                sprintf('Missing per-variant display width input for "%s".', $field),
            );
        }
    }
}
