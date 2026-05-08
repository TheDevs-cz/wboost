<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeSocialNetworkTemplateVariantImageRenderer;
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

        $rendered = $twig->render('api/social_network_template_variant_render.html.twig', [
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

    private function loadVariant(string $id): \WBoost\Web\Entity\SocialNetworkTemplateVariant
    {
        $repository = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class);

        return $repository->get(\Ramsey\Uuid\Uuid::fromString($id));
    }

    private function getRendererFake(): FakeSocialNetworkTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(SocialNetworkTemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeSocialNetworkTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
