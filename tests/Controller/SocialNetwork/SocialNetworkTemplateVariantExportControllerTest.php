<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Controller\SocialNetwork\SocialNetworkTemplateVariantDownloadController;
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
 * - The exportPng LiveAction stashes state in the session and redirects.
 * - The download controller pops the session bag and streams the PNG.
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
        // Component mounted — the page contains its data-controller marker.
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

        // Variant 1 has 4 inputs:
        //   headline (unlocked, not hidable)
        //   tagline  (unlocked, not hidable, uppercase)
        //   ?        (locked, unnamed) — must be skipped
        //   badge    (unlocked, hidable)
        //
        // Non-locked → 3 entries in textValues; hidable+unlocked → 1 entry in hiddenValues.
        self::assertCount(3, $component->textValues, 'textValues should have one entry per non-locked input');
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, $component->textValues);
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID, $component->textValues);
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID, $component->textValues);
        self::assertArrayNotHasKey(
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID,
            $component->textValues,
            'locked inputs must not be addressable via textValues',
        );

        self::assertCount(1, $component->hiddenValues, 'hiddenValues should have one entry per hidable+non-locked input');
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
        // parser does not. If anyone accidentally reverts to dot notation,
        // this assertion fails and we catch the bug at PR time, not in prod.
        self::assertStringContainsString(
            'textValues[' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID . ']',
            $rendered,
        );
        self::assertStringNotContainsString(
            'textValues.' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID,
            $rendered,
            'dot notation must not be used — Live Component value-store rejects keys containing hyphens',
        );
    }

    public function testExportPngActionRedirectsThroughToDownloadAndStreamsPng(): void
    {
        $client = self::createClient();
        $userRepository = self::getContainer()->get(UserRepository::class);
        $user = $userRepository->get(TestDataFixture::USER_1_EMAIL);

        $variant = $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'SocialNetwork:VariantFiller',
            data: [
                'variant' => $variant,
                'textValues' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Hello'],
                'hiddenValues' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID => true],
            ],
            client: $client,
        )->actingAs($user);

        $testComponent->call('exportPng');

        $response = $client->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString(
            '/social-network-template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download',
            $response->getTargetUrl(),
        );

        // Follow the redirect — same client, same cookie jar, so the session
        // bag set by the LiveAction is visible to the download controller.
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith(self::PNG_MAGIC, (string) $client->getResponse()->getContent());

        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        // PostMount pre-populates every non-locked input with an empty default
        // (so the JS valueStore knows about every key). Untouched fields are
        // sent as empty-string overrides, which is the correct semantic — the
        // user MAY want to blank a default. We assert the user-typed value
        // round-trips and that the keys are inputIds, not names or indices.
        self::assertSame(
            'Hello',
            $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null,
            'headline override applied by inputId',
        );
        self::assertTrue(
            $lastCall['hidden'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID] ?? false,
            'badge hide override applied by inputId',
        );
    }

    public function testDownloadEndpointForbiddenForOtherUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request(
            'GET',
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
