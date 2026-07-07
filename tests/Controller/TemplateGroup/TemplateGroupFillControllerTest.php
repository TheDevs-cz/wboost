<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupFillController
 * @covers \WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders
 */
final class TemplateGroupFillControllerTest extends WebTestCase
{
    private function fillUrl(): string
    {
        return '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill';
    }

    public function testFillPageRendersOneUnifiedInputPerPlaceholder(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->fillUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-controller~="group-fill"]');

        // Both member variants carry the SAME inputId — the unified form must
        // offer it exactly ONCE.
        $sharedInputFields = $crawler->filter('input[name="textValues[' . TestDataFixture::GROUP_SHARED_INPUT_ID . ']"]');
        self::assertCount(1, $sharedInputFields);
        self::assertSame('headline', $crawler->filter('label[for="group-fill-text-' . TestDataFixture::GROUP_SHARED_INPUT_ID . '"]')->text());

        // One live preview per member variant, each wired to its own preview
        // endpoint; the manually-added ungrouped variant gets none.
        $previews = $crawler->filter('[data-group-fill-target="preview"]');
        self::assertCount(2, $previews);
        self::assertSelectorExists('[data-preview-endpoint$="/fill-preview/' . TestDataFixture::GROUPED_SOCIAL_VARIANT_ID . '"]');
        self::assertSelectorExists('[data-preview-endpoint$="/fill-preview/' . TestDataFixture::GROUPED_CUSTOM_VARIANT_ID . '"]');
        self::assertSelectorNotExists('[data-preview-endpoint$="/fill-preview/' . TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID . '"]');

        // The form POSTs to the group ZIP export.
        $form = $crawler->filter('form[data-controller~="group-fill"]');
        self::assertStringEndsWith('/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', (string) $form->attr('action'));
    }

    public function testFillPageIsForbiddenForNonDesigner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->fillUrl());

        self::assertResponseStatusCodeSame(403);
    }
}
