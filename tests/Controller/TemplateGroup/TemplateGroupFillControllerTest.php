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
        // offer it exactly ONCE: one mirror field (the popovers write into it,
        // the group-fill controller re-renders on its input event) and one
        // popover — the single-variant page's editor, shared by every
        // dimension's pencil.
        $sharedInputFields = $crawler->filter('input[name="textValues[' . TestDataFixture::GROUP_SHARED_INPUT_ID . ']"][data-text-mirror="' . TestDataFixture::GROUP_SHARED_INPUT_ID . '"][data-action="input->group-fill#changed"]');
        self::assertCount(1, $sharedInputFields);
        $popovers = $crawler->filter('.fill-popover[data-variant-fill-overlay-target="popover"][data-inputid="' . TestDataFixture::GROUP_SHARED_INPUT_ID . '"]');
        self::assertCount(1, $popovers);
        self::assertSame('headline', $crawler->filter('label[for="fill-text-' . TestDataFixture::GROUP_SHARED_INPUT_ID . '"]')->text());
        self::assertCount(1, $popovers->filter('textarea[data-action*="variant-fill-overlay#syncText"]'));

        // The shared overlay controller is mounted on the same form, with the
        // single page's chrome: both switches on, the "Všechna pole" panel.
        $form = $crawler->filter('form[data-controller~="variant-fill-overlay"]');
        self::assertCount(1, $form);
        self::assertCount(1, $crawler->filter('form.fill-form.fill-highlight-on.fill-captions-on'));
        self::assertCount(1, $crawler->filter('#fill-highlight-toggle[checked]'));
        self::assertCount(1, $crawler->filter('#fill-captions-toggle[checked][data-action="change->variant-fill-overlay#toggleCaptions"]'));
        self::assertCount(1, $crawler->filter('[data-variant-fill-overlay-target="panel"]'));
        self::assertCount(1, $crawler->filter('[data-variant-fill-overlay-target="panelButton"]'));

        // One live preview per member variant, each wired to its own preview
        // endpoint; the manually-added ungrouped variant gets none. Each is a
        // fill SURFACE carrying its own canvas width + reflow payload, with a
        // text box AND an image box for the shared placeholders — a pencil on
        // every dimension, all opening the one popover above.
        $previews = $crawler->filter('[data-group-fill-target="preview"]');
        self::assertCount(2, $previews);
        $surfaces = $crawler->filter('[data-variant-fill-overlay-target="surface"]');
        self::assertCount(2, $surfaces);
        foreach ([TestDataFixture::GROUPED_PRESET_VARIANT_ID, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID] as $variantId) {
            $surface = $crawler->filter('[data-variant-fill-overlay-target="surface"][data-variant-id="' . $variantId . '"]');
            self::assertCount(1, $surface);
            self::assertNotSame('', (string) $surface->attr('data-canvas-width'));
            /** @var array{inputs: array<string, array{frame: null|array{x: float}}>} $layout */
            $layout = json_decode((string) $surface->attr('data-layout'), true, 512, JSON_THROW_ON_ERROR);
            $frame = $layout['inputs'][TestDataFixture::GROUP_SHARED_INPUT_ID]['frame'];
            self::assertIsArray($frame);
            self::assertSame(80.0, (float) $frame['x']);
            self::assertCount(1, $surface->filter('.fill-box--text[data-inputid="' . TestDataFixture::GROUP_SHARED_INPUT_ID . '"] [data-action="variant-fill-overlay#openPopover"]'));
            self::assertCount(1, $surface->filter('.fill-box--image[data-inputid="' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '"] [data-action="variant-fill-overlay#openImageModal"]'));
        }
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

        // The picker is a Bootstrap modal the overlay's openImageModal finds
        // by data-image-modal; the panel's image card + every dimension's
        // eye toggle the ONE hidden hide checkbox of the slot.
        self::assertSelectorExists('.modal[data-image-modal="' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '"]');
        self::assertCount(1, $crawler->filter('.fill-popover--image[data-inputid="' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '"]'));
        self::assertCount(1, $crawler->filter('input[name="images[' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '][hide]"][data-image-hide="' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '"]'));
        self::assertGreaterThanOrEqual(3, $crawler->filter('[data-hide-toggle="' . TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID . '"]')->count());
    }

    /**
     * The text-echo chrome must be CONSISTENT with the per-variant payload:
     * exactly the dimensions with an echo payload get a base <img> + echo
     * <canvas>, every text input carries its data-input-id handle, and the
     * shared classic scripts load iff anything is echo-capable. Asserted
     * against whatever eligibility the fixture actually resolves to.
     */
    public function testEchoChromeIsConsistentWithThePerVariantPayload(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', $this->fillUrl());
        self::assertResponseIsSuccessful();

        $variantsAttr = $crawler->filter('form[data-controller~="group-fill"]')->attr('data-group-fill-variants-value');
        self::assertIsString($variantsAttr);
        /** @var list<array{variantId: string, echo: null|array{objects: list<mixed>, inputs: array<string, mixed>}}> $variants */
        $variants = json_decode($variantsAttr, true, 512, JSON_THROW_ON_ERROR);

        $echoCount = 0;
        foreach ($variants as $variant) {
            $canvas = $crawler->filter(sprintf('canvas.group-fill-echo-canvas[data-variant-id="%s"]', $variant['variantId']));
            $base = $crawler->filter(sprintf('img.group-fill-echo-base[data-variant-id="%s"]', $variant['variantId']));
            if ($variant['echo'] !== null) {
                $echoCount++;
                self::assertCount(1, $canvas, 'an echo-capable dimension carries its echo canvas');
                self::assertCount(1, $base, 'an echo-capable dimension carries its base img');
                self::assertNotSame([], $variant['echo']['objects']);
            } else {
                self::assertCount(0, $canvas);
                self::assertCount(0, $base);
            }
        }

        self::assertCount(
            $echoCount > 0 ? 1 : 0,
            $crawler->filter('script[src*="fill_text_echo"]'),
            'the painter loads iff anything is echo-capable',
        );

        // Every unified text input carries the echo's direct handle.
        $crawler->filter('input[name^="textValues["]')->each(function ($node): void {
            self::assertNotSame('', (string) $node->attr('data-input-id'));
        });
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
