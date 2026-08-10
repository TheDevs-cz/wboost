<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\AddTemplateGroupDimensionController
 */
final class AddTemplateGroupDimensionControllerTest extends WebTestCase
{
    public function testPageRendersWithGalleryBackgroundPicker(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/add-dimension');

        self::assertResponseIsSuccessful();
        // The background field is the gallery picker, not a raw file input.
        self::assertSelectorExists('[data-controller="background-picker"]');
        self::assertSelectorExists('#backgroundGalleryModal');
        self::assertSelectorNotExists('input[type="file"][name*="backgroundImage"]');
    }
}
