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
        self::assertSelectorExists('[data-preview-endpoint$="/fill-preview/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID . '"]');
        self::assertSelectorExists('[data-preview-endpoint$="/fill-preview/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID . '"]');
        self::assertSelectorNotExists('[data-preview-endpoint$="/fill-preview/' . TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID . '"]');

        // The form POSTs to the group ZIP export.
        $form = $crawler->filter('form[data-controller~="group-fill"]');
        self::assertStringEndsWith('/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', (string) $form->attr('action'));
    }

    public function testImagePlaceholderOffersGalleryPickAndOwnUpload(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->fillUrl());

        self::assertResponseIsSuccessful();

        // Both member variants share the image placeholder — one unified slot.
        $imageFields = $crawler->filter('input[name="images[' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '][imageId]"]');
        self::assertCount(1, $imageFields);

        // The slot is unrestricted, so the user may upload their own picture:
        // the picker mounts the shared fill-gallery controller pointing at the
        // group-scoped upload endpoint, with its dropzone file input inside.
        $picker = $crawler->filter('[data-controller="fill-gallery"]');
        self::assertCount(1, $picker);
        self::assertStringEndsWith(
            '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/placeholders/' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '/upload',
            (string) $picker->attr('data-fill-gallery-upload-url-value'),
        );
        self::assertCount(1, $picker->filter('input[type="file"][data-fill-gallery-target="fileInput"]'));

        // Freshly uploaded pictures land in the picker's option grid.
        self::assertSelectorExists('[data-group-fill-target="imageOptions"][data-input-id="' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '"]');
    }

    public function testFillPageIsAccessibleToSharedUser(): void
    {
        // The fill surfaces are project-VIEW gated: a user the project is
        // merely SHARED with (read-only, no designer role) can fill & export —
        // but the designer chrome (group editor link) must not render.
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::INVITED_USER_EMAIL);

        $client->request('GET', $this->fillUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-controller~="group-fill"]');
        self::assertSelectorNotExists('a[href="/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor"]');
    }

    public function testFillPageIsForbiddenForUnrelatedUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', $this->fillUrl());

        self::assertResponseStatusCodeSame(403);
    }
}
