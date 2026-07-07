<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupFromTemplateController
 */
final class TemplateGroupFromTemplateControllerTest extends WebTestCase
{
    private function pickerUrl(): string
    {
        return '/project/' . TestDataFixture::PROJECT_1_ID . '/template-groups/from-template';
    }

    public function testListsBothModulesTemplatesWithVariantSourceLinks(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->pickerUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Insta Template 1');
        self::assertSelectorTextContains('body', 'Custom Template 1');
        // Grouped templates are valid design sources too.
        self::assertSelectorTextContains('body', 'Group Campaign');

        // Each variant links into the wizard carrying the design source.
        self::assertSelectorExists(sprintf(
            'a[href*="sourceModule=social"][href*="sourceVariantId=%s"]',
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
        ));
        self::assertSelectorExists(sprintf(
            'a[href*="sourceModule=custom"][href*="sourceVariantId=%s"]',
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
        ));

        // Templates from other projects must not leak in.
        self::assertSelectorNotExists(sprintf(
            'a[href*="sourceVariantId=%s"]',
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID,
        ));
    }

    public function testForbiddenForOwnerWithoutDesignerRole(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->pickerUrl());

        self::assertResponseStatusCodeSame(403);
    }
}
