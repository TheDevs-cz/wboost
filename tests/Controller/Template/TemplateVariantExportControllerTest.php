<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Twig\Components\Template\VariantFiller;

/**
 * Covers the user-fill page flow end-to-end (ported from the retired social
 * module suite — the behaviours now live in the unified Template module, and
 * the former-social fixture variant is the richest one: containers, rich
 * text, image placeholders):
 *
 * - The export page renders the Template:VariantFiller Live Component
 *   (regression for the IsGranted-at-class-level bug, where the Symfony
 *   Security listener could not resolve the LiveProp `$variant` as a method
 *   argument and the entire component blew up at first render).
 * - The component's PostMount pre-populates `textValues` / `hiddenValues`
 *   for every non-locked input so the front-end value-store has every
 *   inputId key when the user starts typing (regression for the
 *   "Invalid model name" error in live_controller.js valueStore.has()).
 * - The rendered template uses `textValues[<uuid>]` bracket notation in
 *   data-model attrs (dot notation breaks valueStore lookups for keys
 *   that contain hyphens like UUIDs).
 * - The download controller reads form-POST input data and streams a PNG
 *   with Content-Disposition: attachment. Plain form POST avoids the Live
 *   Component / Turbo binary-response confusion that surfaced in prod.
 * - The Gotenberg render template keeps its Fabric v7 pins (custom-property
 *   restore, obj.set() overrides, font force-load, container reflow hooks).
 *
 * @covers \WBoost\Web\Twig\Components\Template\VariantFiller
 * @covers \WBoost\Web\Controller\Template\TemplateVariantDownloadController
 * @covers \WBoost\Web\Controller\Template\TemplateVariantExportController
 */
final class TemplateVariantExportControllerTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string PNG_MAGIC = "\x89PNG\r\n\x1a\n";
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    public function testExportPageRedirectsGuestToLogin(): void
    {
        $client = self::createClient();

        $client->request(
            'GET',
            '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
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
            '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"]');

        // The component mounts with loading="defer": the page GET carries only
        // the live stub + the loading placeholder, and the expensive first
        // render (1-3 Gotenberg calls) happens in the follow-up Live request.
        self::assertSelectorTextContains('[data-controller~="live"]', 'Připravuji šablonu k vyplnění');
    }

    public function testExportPageForbiddenForOtherUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request(
            'GET',
            '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testPostMountPrePopulatesWritableLivePropsForNonLockedInputs(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
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

    /**
     * The fill page re-renders 2-3 times per keystroke and base64s the result
     * into the Live response, so format choice here is the hot path:
     *
     *  - backdrop / full preview -> WebP. Image-rich, and ~10-14x smaller than
     *    PNG, which is what keeps the Live response small.
     *  - overlay slices -> PNG. Flat transparent layers, where PNG is actually
     *    FASTER to encode (measured 0.147s vs 0.220s) for ~7KB more.
     *
     * Lossy previews are deliberate; the EXPORT paths stay PNG and are locked
     * by the contract assertions elsewhere in this file.
     */
    public function testFillPagePreviewsUseWebpWhileOverlaySlicesStayPng(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        );

        /** @var VariantFiller $component */
        $component = $testComponent->component();

        // This variant has image placeholders, so the interactive path is
        // backdrop + overlays rather than the single flat preview.
        self::assertTrue($component->hasImagePlaceholders());
        self::assertStringStartsWith('data:image/webp;base64,', $component->backdropDataUri());

        // Guard against this test quietly becoming vacuous: if a fixture change
        // ever removed the design content above the placeholders, the loop
        // below would assert nothing and still pass.
        $overlays = $component->overlaySlices();
        self::assertNotEmpty($overlays, 'fixture must still produce overlay slices for this to test anything');

        foreach ($overlays as $slice) {
            self::assertStringStartsWith(
                'data:image/png;base64,',
                $slice['dataUri'],
                'transparent overlay slices stay PNG — faster to encode for flat content',
            );
        }

        $formats = array_column($this->getRendererFake()->calls, 'format');
        self::assertNotEmpty($formats);
        self::assertContains('webp', $formats, 'the backdrop must have been rendered as WebP');
    }

    public function testRenderedTemplateUsesBracketNotationForUuidKeys(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
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
     * Two render-path contracts of the fill page:
     *
     * - The page GET itself renders NOTHING through Gotenberg — the component
     *   is deferred (`loading="defer"`), so the shell answers instantly and
     *   the expensive first render happens in the follow-up Live request.
     *   Rendering inline used to burn the page request's execution budget
     *   whenever the renderer was busy (MaxExecutionTimeError fatals).
     * - The component's preview must use `renderToBytes()`, NOT `render()` +
     *   `sendContent()` — the StreamedResponse flush() would commit headers
     *   before Symfony finished assembling the outer response (the production
     *   "Cannot modify header information" regression).
     */
    public function testExportPageRenderUsesBytesPathForPreviewNotStreamedResponse(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request(
            'GET',
            '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        self::assertSame(
            [],
            $this->getRendererFake()->calls,
            'the deferred page GET must not render anything through Gotenberg',
        );

        // The deferred Live request is where the preview actually renders.
        $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        )->render();

        // Re-fetched: the kernel reboots between requests, re-creating the fake.
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
            name: 'Template:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        );

        $rendered = (string) $testComponent->render();

        // The export must be a plain form POST with Turbo disabled — anything
        // else (LiveAction redirect, fetch+blob) puts us back into the Turbo
        // binary-response trap that sent the user a broken file in prod.
        self::assertStringContainsString('method="POST"', $rendered);
        self::assertStringContainsString(
            '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
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
            name: 'Template:VariantFiller',
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
     * Regression for the production 500 on the FIRST click of a fill-page eye
     * icon (Sentry, 2026-08-05): `Input "…".hide must be a boolean.`
     *
     * The eye flips a hidden mirror checkbox that carries `value="1"` (the
     * plain download POST needs it). live_controller's `getValueFromElement()`
     * reads such a checkbox as its `value` ATTRIBUTE — the string "1" — or
     * `null` when cleared, never a bool, and a writable-path update is written
     * onto the array with no hydrator coercion. `ResolveTextOverrides` then
     * strictly rejected the string and the Live re-render blew up inside
     * VariantFiller.html.twig.
     *
     * Simulates both wire values the browser actually sends.
     */
    public function testEyeCheckboxStringWireValueHydratesAsBooleanHide(): void
    {
        $client = self::createClient();
        $userRepository = self::getContainer()->get(UserRepository::class);
        $user = $userRepository->get(TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);
        $badgeId = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID;

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        )->actingAs($user);

        // Wire-equivalent of: user clicks the eye, the mirror checkbox becomes
        // checked, JS writes valueStore.dirtyProps['hiddenValues.<uuid>'] = "1".
        $testComponent->set('hiddenValues.' . $badgeId, '1');

        /** @var VariantFiller $component */
        $component = $testComponent->component();
        self::assertTrue(
            $component->hiddenValues[$badgeId] ?? null,
            'the raw "1" the checkbox sends must normalize to a real bool',
        );

        (string) $testComponent->render();

        $previewCall = $this->lastPreviewCall();
        self::assertNotNull($previewCall);
        self::assertTrue(
            $previewCall['hidden'][$badgeId] ?? false,
            'the hide must reach the renderer as a resolved override',
        );

        // Un-hide: an unchecked checkbox with a `value` attribute is sent as
        // null, which the strict parser rejected just as hard as "1".
        $testComponent->set('hiddenValues.' . $badgeId, null);

        /** @var VariantFiller $component */
        $component = $testComponent->component();
        self::assertFalse(
            $component->hiddenValues[$badgeId] ?? null,
            'clearing the checkbox sends null, which must normalize to false',
        );

        (string) $testComponent->render();

        $previewCall = $this->lastPreviewCall();
        self::assertNotNull($previewCall);
        self::assertFalse($previewCall['hidden'][$badgeId] ?? false);
    }

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
            'rich_text_overrides' => [],
            'list_configs' => [],
            'hidden_overrides' => [],
            'containers' => [],
            'strict_container_overflow' => false,
            'fabric_inline_script' => '/* fabric stub */',
            'break_word_inline_script' => '/* break-word stub */',
            'container_layout_inline_script' => '/* container-layout stub */',
            'rich_text_runs_inline_script' => '/* rich-text-runs stub */',
            'rich_text_blocks_inline_script' => '/* rich-text-blocks stub */',
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
     */
    public function testRenderTemplateUsesObjSetForOverridesNotDirectAssignment(): void
    {
        $twig = self::getContainer()->get('twig');

        $rendered = $twig->render('api/template_variant_render.html.twig', [
            'variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            'canvas_json' => '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            'font_faces' => [],
            'text_overrides' => [],
            'rich_text_overrides' => [],
            'list_configs' => [],
            'hidden_overrides' => [],
            'containers' => [],
            'strict_container_overflow' => false,
            'fabric_inline_script' => '/* fabric stub */',
            'break_word_inline_script' => '/* break-word stub */',
            'container_layout_inline_script' => '/* container-layout stub */',
            'rich_text_runs_inline_script' => '/* rich-text-runs stub */',
            'rich_text_blocks_inline_script' => '/* rich-text-blocks stub */',
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
     * Pins the container-reflow hook in the render template: the designed
     * geometry snapshot (prepareFabricContainers) must run BEFORE the override
     * loop (members must still hold their designed text), the reflow
     * (applyFabricLayout) AFTER it (heights re-wrapped), and strict mode must
     * signal overflow via the CONTAINER_OVERFLOW uncaught-exception marker the
     * renderer parses out of Gotenberg's failOnConsoleExceptions error body.
     */
    public function testRenderTemplateRunsContainerLayoutAroundOverrides(): void
    {
        $twig = self::getContainer()->get('twig');

        $rendered = $twig->render('api/template_variant_render.html.twig', [
            'variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            'canvas_json' => '{"version":"5.2.4","objects":[],"backgroundImage":null}',
            'font_faces' => [],
            'text_overrides' => [],
            'rich_text_overrides' => [],
            'list_configs' => [],
            'hidden_overrides' => [],
            'containers' => [
                ['id' => 'c-1', 'maxHeight' => 120, 'memberInputIds' => ['a-1', 'b-2']],
            ],
            'strict_container_overflow' => true,
            'fabric_inline_script' => '/* fabric stub */',
            'break_word_inline_script' => '/* break-word stub */',
            'container_layout_inline_script' => '/* container-layout stub */',
            'rich_text_runs_inline_script' => '/* rich-text-runs stub */',
            'rich_text_blocks_inline_script' => '/* rich-text-blocks stub */',
        ]);

        $prepare = strpos($rendered, 'WBoostContainerLayout.prepareFabricContainers');
        $overrideLoop = strpos($rendered, 'obj.set({ text: String(textOverrides[idKey]) })');
        $apply = strpos($rendered, 'WBoostContainerLayout.applyFabricLayout');

        self::assertNotFalse($prepare);
        self::assertNotFalse($overrideLoop);
        self::assertNotFalse($apply);
        self::assertLessThan($overrideLoop, $prepare, 'designed-geometry snapshot must run before overrides');
        self::assertLessThan($apply, $overrideLoop, 'reflow must run after overrides');

        // The container definitions + strict flag reach the page...
        self::assertStringContainsString('"memberInputIds":["a-1","b-2"]', $rendered);
        self::assertStringContainsString('const strictContainerOverflow = true;', $rendered);

        // ...and the overflow signal is the uncaught-exception marker, thrown
        // via setTimeout so it escapes the template's try/catch.
        self::assertStringContainsString("throw new Error('CONTAINER_OVERFLOW:'", $rendered);
        self::assertStringContainsString('setTimeout(() => {', $rendered);
    }

    /**
     * Regression for "the export uses the wrong font". The headless Chromium
     * render came out in a serif fallback even though the editor showed the
     * correct webfont. Root cause: a Canvas 2D context does NOT trigger lazy
     * @font-face loading, and `document.fonts.ready` only awaits faces that
     * are ALREADY loading — the template MUST construct FontFace objects from
     * the inlined data URIs and await load() before touching the canvas.
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
            'rich_text_overrides' => [],
            'list_configs' => [],
            'hidden_overrides' => [],
            'containers' => [],
            'strict_container_overflow' => false,
            'fabric_inline_script' => '/* fabric stub */',
            'break_word_inline_script' => '/* break-word stub */',
            'container_layout_inline_script' => '/* container-layout stub */',
            'rich_text_runs_inline_script' => '/* rich-text-runs stub */',
            'rich_text_blocks_inline_script' => '/* rich-text-blocks stub */',
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
            uri: '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
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
            uri: '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
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
        // What the user downloads and keeps must stay lossless. Locking the
        // requested format (not only the emitted bytes) is what stops a future
        // renderer-default change from quietly making downloads lossy.
        self::assertSame('png', $lastCall['format'], 'the web download must stay lossless PNG');
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
            '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The fill component for a variant WITH image placeholders renders the
     * interactive `variant-image-fill` canvas: the controller wiring, the
     * per-slot hidden placement fields, the backdrop source element, and the
     * allowed-folder images as pickable thumbnails. The backdrop itself is
     * rendered with every placeholder hidden so the live Fabric objects are the
     * only pictures shown in those slots. (Asserted on the component render —
     * the page GET carries only the deferred placeholder.)
     */
    public function testImageVariantRendersInteractiveFillCanvas(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $rendered = (string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        )->render();

        $crawler = new Crawler($rendered);
        self::assertCount(1, $crawler->filter('[data-controller~="variant-image-fill"]'));
        self::assertCount(1, $crawler->filter('[data-variant-image-fill-target="canvas"]'));
        self::assertCount(1, $crawler->filter('#variant-backdrop-source'));
        self::assertCount(1, $crawler->filter(
            'input[name="images[' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID . '][imageId]"]',
        ));
        // The photo slot offers its allowed-folder image as a pickable thumbnail.
        self::assertGreaterThan(0, $crawler->filter(
            '[data-variant-image-fill-imageid-param="' . TestDataFixture::FILE_IN_ALLOWED_ID . '"]',
        )->count());

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
            uri: '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
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
        self::assertSame('png', $lastCall['format'], 'the web download must stay lossless PNG');
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
            uri: '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
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

    private function loadVariant(string $id): TemplateVariant
    {
        $repository = self::getContainer()->get(TemplateVariantRepository::class);

        return $repository->get(Uuid::fromString($id));
    }

    /**
     * The most recent preview render (the fill page also renders overlay
     * slices, so the last call is not necessarily the one we want).
     *
     * @return null|array{texts: array<string, string>, hidden: array<string, bool>, mode: string, format: string, ...}
     */
    private function lastPreviewCall(): null|array
    {
        foreach (array_reverse($this->getRendererFake()->calls) as $call) {
            if ($call['mode'] === 'renderToBytes') {
                return $call;
            }
        }

        return null;
    }

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
