<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Twig\Components\SocialNetwork\VariantFiller;

/**
 * Covers the user-fill page flow end-to-end:
 *
 * - The export page renders the SocialNetwork:VariantFiller Live Component
 *   (regression for the Stage 5 IsGranted-at-class-level bug, where the
 *   Symfony Security listener could not resolve the LiveProp `$variant` as
 *   a method argument and the entire component blew up at first render).
 * - The component's PostMount pre-populates `textValues` / `hiddenValues`
 *   for every non-locked input so the front-end value-store has every
 *   inputId key when the user starts typing (regression for the Stage 5
 *   "Invalid model name" error in live_controller.js valueStore.has()).
 * - The rendered template uses `textValues[<uuid>]` bracket notation in
 *   data-model attrs (dot notation breaks valueStore lookups for keys
 *   that contain hyphens like UUIDs).
 * - The download controller reads form-POST input data and streams a PNG
 *   with Content-Disposition: attachment. Plain form POST avoids the Live
 *   Component / Turbo binary-response confusion that surfaced in prod.
 *
 * @covers \WBoost\Web\Twig\Components\SocialNetwork\VariantFiller
 * @covers \WBoost\Web\Controller\SocialNetwork\SocialNetworkTemplateVariantDownloadController
 * @covers \WBoost\Web\Controller\SocialNetwork\SocialNetworkTemplateVariantExportController
 */
final class SocialNetworkTemplateVariantExportControllerTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string PNG_MAGIC = "\x89PNG\r\n\x1a\n";
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    public function testExportPageRedirectsGuestToLogin(): void
    {
        $client = self::createClient();

        $client->request(
            'GET',
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        // 302 to /login or similar — we just want to confirm it does NOT 500
        // (the IsGranted misconfiguration would have blown up at this point).
        self::assertResponseRedirects();
    }

    public function testExportPageRendersForVariantOwner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request(
            'GET',
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"]');
    }

    public function testExportPageForbiddenForOtherUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request(
            'GET',
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testPostMountPrePopulatesWritableLivePropsForNonLockedInputs(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'SocialNetwork:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        );

        /** @var VariantFiller $component */
        $component = $testComponent->component();

        // Variant 1 has 4 inputs: headline, tagline, locked-unnamed, badge.
        // Non-locked → 3 entries in textValues; hidable+unlocked → 1 in hiddenValues.
        self::assertCount(3, $component->textValues);
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, $component->textValues);
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID, $component->textValues);
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID, $component->textValues);
        self::assertArrayNotHasKey(
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID,
            $component->textValues,
        );

        self::assertCount(1, $component->hiddenValues);
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID, $component->hiddenValues);
    }

    public function testRenderedTemplateUsesBracketNotationForUuidKeys(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'SocialNetwork:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        );

        $rendered = (string) $testComponent->render();

        // Bracket notation tolerates hyphens; dot notation in the JS model
        // parser does not. If anyone reverts to dot notation, this fails.
        self::assertStringContainsString(
            'textValues[' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID . ']',
            $rendered,
        );
        self::assertStringNotContainsString(
            'data-model="on(change)|textValues.' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID,
            $rendered,
        );
    }

    /**
     * Regression for the production "Cannot modify header information"
     * warning: the export page render must use `renderToBytes()` for the
     * preview, NOT `render()` + `sendContent()`. The latter calls flush()
     * inside the StreamedResponse callback, which commits response headers
     * to the browser before Symfony has finished assembling the outer HTML
     * response — so cookies / Content-Type / Content-Length are dropped
     * and the browser content-sniffs a header-less body.
     */
    public function testExportPageRenderUsesBytesPathForPreviewNotStreamedResponse(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request(
            'GET',
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $fake = $this->getRendererFake();
        $previewCalls = array_filter($fake->calls, static fn (array $c): bool => $c['mode'] === 'renderToBytes');
        $streamCalls = array_filter($fake->calls, static fn (array $c): bool => $c['mode'] === 'render');

        self::assertNotEmpty($previewCalls, 'preview must use renderToBytes() to avoid StreamedResponse + flush() side-effects');
        self::assertEmpty($streamCalls, 'preview must NOT use render() (StreamedResponse) — that path is reserved for the download endpoint');
    }

    public function testRenderedTemplateContainsFormPostingToDownloadRoute(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'SocialNetwork:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        );

        $rendered = (string) $testComponent->render();

        // The export must be a plain form POST with Turbo disabled — anything
        // else (LiveAction redirect, fetch+blob) puts us back into the Turbo
        // binary-response trap that sent the user a broken file in prod.
        self::assertStringContainsString('method="POST"', $rendered);
        self::assertStringContainsString(
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
            $rendered,
        );
        self::assertStringContainsString('data-turbo="false"', $rendered);
        self::assertStringContainsString(
            'name="textValues[' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID . ']"',
            $rendered,
        );
    }

    /**
     * Regression for "live preview does not redraw after typing".
     *
     * Simulates the exact wire format the browser sends when a user blurs an
     * input bound to `data-model="on(change)|textValues[<uuid>]"`:
     *   { updated: { "textValues.<uuid>": "Hello" } }
     *
     * After hydration, the component's $textValues must contain the typed
     * value AND the next previewDataUri() call must pass it to the renderer.
     * If either link is broken, the AJAX response carries the same <img>
     * bytes and the page visibly does not change.
     */
    public function testLivePropNestedWriteFlowsIntoRendererCall(): void
    {
        $client = self::createClient();
        $userRepository = self::getContainer()->get(UserRepository::class);
        $user = $userRepository->get(TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'SocialNetwork:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        )->actingAs($user);

        // Wire-equivalent of: user types "Hello" into the headline field,
        // blur fires, JS writes valueStore.dirtyProps['textValues.<uuid>'].
        $testComponent->set(
            'textValues.' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID,
            'Hello',
        );

        // After the AJAX round-trip the hydrated component reflects the typed
        // value, and rendering it must invoke the renderer with that override.
        /** @var VariantFiller $component */
        $component = $testComponent->component();
        self::assertSame(
            'Hello',
            $component->textValues[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null,
            'LiveProp nested write must hydrate into $textValues server-side',
        );

        // Force a fresh render cycle and assert the renderer saw the value.
        (string) $testComponent->render();

        $fake = $this->getRendererFake();
        $previewCall = null;
        foreach (array_reverse($fake->calls) as $call) {
            if ($call['mode'] === 'renderToBytes') {
                $previewCall = $call;
                break;
            }
        }

        self::assertNotNull($previewCall, 'preview must have been rendered via renderToBytes');
        self::assertSame(
            'Hello',
            $previewCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null,
            'previewDataUri() must pass the freshly-typed value to the renderer',
        );
    }

    /**
     * The form POST a user submits drives the renderer with the typed inputs.
     * This is the regression for "the placeholder text is not replaced with
     * the input value" — if the controller fails to wire the form's
     * textValues array into the override resolver, the renderer never sees
     * what the user typed.
     */
    /**
     * The Gotenberg render template MUST manually restore custom properties
     * (inputId, name, locked, etc.) from the source JSON onto each Fabric
     * object after loadFromJSON. Fabric v7's _fromObject does not do this
     * automatically — only registered customProperties or known
     * SerializedObjectProps survive the deserialization. Without the
     * restore pass, every Textbox loaded in headless Chromium has
     * obj.inputId === undefined, the override-by-inputId find() returns
     * nothing, and the user sees the placeholder text instead of their
     * typed value (the iteration-5 production bug).
     *
     * This test pins the restore logic to the template so a future edit
     * cannot silently remove it.
     */
    public function testRenderTemplateRestoresCustomPropertiesAfterLoadFromJSON(): void
    {
        $twig = self::getContainer()->get('twig');

        $rendered = $twig->render('api/template_variant_render.html.twig', [
            'variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            'canvas_json' => '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            'font_faces' => [],
            'text_overrides' => [],
            'hidden_overrides' => [],
            'fabric_inline_script' => '/* fabric stub */',
        ]);

        // The restore pass must:
        //   (1) source the canvas objects from the parsed JSON (not from
        //       canvas.getObjects(), since Fabric v7 strips custom props);
        //   (2) iterate the post-load live objects;
        //   (3) copy each custom property if defined on the source.
        self::assertStringContainsString('CANVAS_CUSTOM_PROPERTIES', $rendered);
        self::assertStringContainsString("'inputId'", $rendered);
        self::assertStringContainsString('canvasJson.objects', $rendered);
        self::assertStringContainsString('source[prop]', $rendered);
    }

    /**
     * Regression for the iteration-7 production bug. When applying overrides,
     * the render template MUST use `obj.set({ text: ... })` — direct property
     * assignment (`obj.text = ...`) updates the string state but Fabric v7's
     * Textbox renders stale glyphs because its layout cache (_styleMap,
     * _textLines, dimensions) is only invalidated through the property-setter
     * chain that `set()` runs.
     *
     * Verified empirically against the prod canvas JSON via
     * /debug/fabric-render-test.html and against a real Gotenberg pipeline
     * render in dev (app:debug:render-variant) — direct assign rendered the
     * placeholder, `set()` rendered the override.
     */
    public function testRenderTemplateUsesObjSetForOverridesNotDirectAssignment(): void
    {
        $twig = self::getContainer()->get('twig');

        $rendered = $twig->render('api/template_variant_render.html.twig', [
            'variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            'canvas_json' => '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            'font_faces' => [],
            'text_overrides' => [],
            'hidden_overrides' => [],
            'fabric_inline_script' => '/* fabric stub */',
        ]);

        // Must use set() for both text and visibility overrides.
        self::assertStringContainsString('obj.set({ text: String(textOverrides[idKey]) })', $rendered);
        self::assertStringContainsString('obj.set({ visible: !hiddenOverrides[idKey] })', $rendered);

        // Direct property assignment must NOT be present — it silently fails
        // for Textbox glyph rendering in Fabric v7.
        self::assertStringNotContainsString('obj.text = String(textOverrides', $rendered);
        self::assertStringNotContainsString('obj.visible = !hiddenOverrides', $rendered);
    }

    /**
     * Regression for "the export uses the wrong font". The headless Chromium
     * render came out in a serif fallback even though the editor showed the
     * correct webfont. Root cause: a Canvas 2D context does NOT trigger lazy
     * @font-face loading, and `document.fonts.ready` only awaits faces that
     * are ALREADY loading — so a purely declarative @font-face is never
     * fetched for canvas-only text and Fabric measures/paints with the
     * fallback. The template MUST construct FontFace objects from the inlined
     * data URIs and await load() before touching the canvas (the editor does
     * the equivalent via FontFaceObserver).
     *
     * This pins the force-load so a future edit cannot regress back to a
     * declaration-only approach.
     */
    public function testRenderTemplateForceLoadsFontsBeforeRendering(): void
    {
        $twig = self::getContainer()->get('twig');

        $rendered = $twig->render('api/template_variant_render.html.twig', [
            'variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            'canvas_json' => '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            'font_faces' => [
                ['family' => 'Rubik (Rubik ExtraBold Italic)', 'src' => 'data:font/woff2;base64,AAAA'],
            ],
            'text_overrides' => [],
            'hidden_overrides' => [],
            'fabric_inline_script' => '/* fabric stub */',
        ]);

        // The faces must reach the client as data the script iterates over.
        self::assertStringContainsString('Rubik (Rubik ExtraBold Italic)', $rendered);

        // ...and be force-loaded via the CSS Font Loading API, then awaited,
        // BEFORE any canvas text is measured or painted.
        self::assertStringContainsString('new FontFace(', $rendered);
        self::assertStringContainsString('fontFace.load()', $rendered);
        self::assertStringContainsString('document.fonts.add(', $rendered);
    }

    /**
     * Regression for the Fabric v7 / PascalCase fallout: even when the
     * variant's canvas contains a Textbox whose `inputId` is properly
     * matched with `inputs[i].inputId`, the override resolver must still
     * find it. This goes through the renderer's resolveTextOverrides so the
     * PNG comes out with the user's text, not the placeholder.
     */
    public function testFormPostHonoursOverridesEvenWithPascalCaseCanvasObjects(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request(
            method: 'POST',
            uri: '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
            parameters: [
                'textValues' => [
                    TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'xx',
                ],
            ],
        );

        self::assertResponseIsSuccessful();

        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertSame(
            'xx',
            $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null,
            'a typed override must survive the form POST and reach the renderer keyed by inputId',
        );
    }

    public function testFormPostDownloadStreamsPngWithUserOverrides(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request(
            method: 'POST',
            uri: '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
            parameters: [
                'textValues' => [
                    TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Hello',
                ],
                'hiddenValues' => [
                    TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID => '1',
                ],
            ],
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringContainsString(
            'attachment',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertStringStartsWith(self::PNG_MAGIC, (string) $client->getResponse()->getContent());

        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];
        self::assertSame(
            'Hello',
            $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null,
            'headline override resolved by inputId from POSTed form data',
        );
        self::assertTrue(
            $lastCall['hidden'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID] ?? false,
            'badge hide flag derived from a present checkbox value',
        );
    }

    public function testDownloadEndpointForbiddenForOtherUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request(
            'POST',
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The fill page for a variant WITH image placeholders renders the
     * interactive `variant-image-fill` canvas: the controller wiring, the
     * per-slot hidden placement fields, the backdrop source element, and the
     * allowed-folder images as pickable thumbnails. The backdrop itself is
     * rendered with every placeholder hidden so the live Fabric objects are the
     * only pictures shown in those slots.
     */
    public function testImageVariantRendersInteractiveFillCanvas(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request(
            'GET',
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="variant-image-fill"]');
        self::assertSelectorExists('[data-variant-image-fill-target="canvas"]');
        self::assertSelectorExists('#variant-backdrop-source');
        self::assertSelectorExists(
            'input[name="images[' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID . '][imageId]"]',
        );
        // The photo slot offers its allowed-folder image as a pickable thumbnail.
        self::assertSelectorExists(
            '[data-variant-image-fill-imageid-param="' . TestDataFixture::FILE_IN_ALLOWED_ID . '"]',
        );

        // The backdrop render hides every placeholder.
        $fake = $this->getRendererFake();
        $backdrop = null;
        foreach (array_reverse($fake->calls) as $call) {
            if ($call['mode'] === 'renderToBytes') {
                $backdrop = $call;
                break;
            }
        }
        self::assertNotNull($backdrop);
        self::assertContains(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $backdrop['imagesHidden']);
        self::assertContains(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID, $backdrop['imagesHidden']);
    }

    /**
     * The form POST that downloads the PNG carries image placements
     * (images[inputId][imageId|scale|offsetX|offsetY|rotation]) alongside the
     * text values. The download controller normalises the string form fields,
     * resolves them through the same ResolveImageOverrides the API uses, and
     * the renderer receives the placement.
     */
    public function testFormPostDownloadPlacesChosenImage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        // The resolver inlines the chosen image → it must exist in the store.
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write('fixtures/in-allowed.png', $bytes);

        $client->request(
            method: 'POST',
            uri: '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
            parameters: [
                'images' => [
                    TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => [
                        'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                        'scale' => '1.5',
                        'offsetX' => '8',
                        'offsetY' => '0',
                        'rotation' => '0',
                    ],
                ],
            ],
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith(self::PNG_MAGIC, (string) $client->getResponse()->getContent());

        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];
        $placed = $lastCall['images'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID] ?? null;
        self::assertIsArray($placed);
        self::assertSame(1.5, $placed['scale']);
        self::assertSame(8.0, $placed['offsetX']);
        self::assertSame(1, $placed['naturalWidth']);
    }

    public function testFormPostDownloadRejectsImageOutsideAllowedFolder(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        // FILE_IN_OTHER is in a folder the photo slot does not allow → 400.
        $client->request(
            method: 'POST',
            uri: '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
            parameters: [
                'images' => [
                    TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => [
                        'imageId' => TestDataFixture::FILE_IN_OTHER_ID,
                    ],
                ],
            ],
        );

        self::assertResponseStatusCodeSame(400);
    }

    private function loadVariant(string $id): \WBoost\Web\Entity\SocialNetworkTemplateVariant
    {
        $repository = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class);

        return $repository->get(\Ramsey\Uuid\Uuid::fromString($id));
    }

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
