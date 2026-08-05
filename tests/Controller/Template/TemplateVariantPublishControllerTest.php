<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Meta\MetaGraphApiInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeMetaGraphApi;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportChannel;

/**
 * Unified-module port of the social publish controller test: same flows
 * against `/template-variant/{id}/publish`, plus the ONE unified-only rule —
 * a variant without a social dimension preset (free-form px/mm/cm) cannot
 * be published at all.
 */
final class TemplateVariantPublishControllerTest extends WebTestCase
{
    /**
     * @param array<string, mixed> $extra
     * @return array<mixed>
     */
    private function publish(
        KernelBrowser $client,
        array $extra = [],
        string $variantId = TestDataFixture::GROUPED_PRESET_VARIANT_ID,
    ): array {
        $client->request(
            'POST',
            '/template-variant/' . $variantId . '/publish',
            $extra,
        );

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function fakeApi(): FakeMetaGraphApi
    {
        $api = self::getContainer()->get(MetaGraphApiInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeMetaGraphApi::class, $api);

        return $api;
    }

    public function testPublishToFacebookPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $data = $this->publish($client, [
            'platform' => 'facebook',
            'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID,
            'caption' => 'Náš nový příspěvek',
            'textValues' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Custom headline'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['ok']);
        self::assertSame(FakeMetaGraphApi::PUBLISHED_POST_ID, $data['postId']);

        // The photo really went to the picked page, with bytes + caption.
        $publishCalls = $this->fakeApi()->callsTo('publishPagePhoto');
        self::assertCount(1, $publishCalls);
        self::assertSame(FakeMetaGraphApi::PAGE_WITHOUT_IG_ID, $publishCalls[0]['args']['pageId']);
        self::assertSame('Náš nový příspěvek', $publishCalls[0]['args']['caption']);
        self::assertGreaterThan(0, $publishCalls[0]['args']['imageBytesLength']);

        // MetaGraphApi uploads these bytes as a DataPart labelled
        // 'wboost-export.png' / 'image/png'. If the renderer ever handed this
        // path WebP, we would be lying to Graph about the payload — a silent,
        // third-party-visible breakage no local assertion would otherwise
        // catch. Lock the requested format here.
        $rendererCalls = $this->getRendererFake()->calls;
        self::assertNotEmpty($rendererCalls);
        foreach ($rendererCalls as $call) {
            self::assertSame('png', $call['format'], 'bytes published to Meta must be PNG, as declared to Graph');
        }

        // Usage tracking: one ExportEvent on the facebook channel.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $event = $entityManager->getRepository(ExportEvent::class)->findOneBy([
            'variantId' => Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID),
        ]);
        self::assertInstanceOf(ExportEvent::class, $event);
        self::assertSame(ExportChannel::Facebook, $event->channel);
    }

    public function testPublishToInstagramUploadsJpegAndCleansUp(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $data = $this->publish($client, [
            'platform' => 'instagram',
            'targetId' => FakeMetaGraphApi::PAGE_WITH_IG_ID,
            'caption' => 'IG post',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['ok']);
        self::assertSame(FakeMetaGraphApi::PUBLISHED_MEDIA_ID, $data['postId']);

        $api = $this->fakeApi();

        $containerCalls = $api->callsTo('createInstagramContainer');
        self::assertCount(1, $containerCalls);
        self::assertSame(FakeMetaGraphApi::IG_USER_ID, $containerCalls[0]['args']['igUserId']);
        // Meta fetches the image itself: the container must reference a public
        // JPEG URL, not raw bytes.
        self::assertIsString($containerCalls[0]['args']['imageUrl']);
        self::assertStringContainsString('social-publish/', $containerCalls[0]['args']['imageUrl']);
        self::assertStringEndsWith('.jpg', $containerCalls[0]['args']['imageUrl']);

        self::assertCount(1, $api->callsTo('publishInstagramContainer'));

        // The temp JPEG is removed after publishing (test storage is local).
        $leftovers = glob('/tmp/wboost-test-uploads/social-publish/*');
        self::assertSame([], $leftovers === false ? [] : $leftovers);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $event = $entityManager->getRepository(ExportEvent::class)->findOneBy([
            'variantId' => Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID),
        ]);
        self::assertInstanceOf(ExportEvent::class, $event);
        self::assertSame(ExportChannel::Instagram, $event->channel);
    }

    public function testFreeFormVariantCannotBePublished(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        // Otherwise perfectly valid request — the preset gate alone refuses it.
        $data = $this->publish($client, [
            'platform' => 'facebook',
            'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID,
            'caption' => 'Should never leave the app',
        ], TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $data);
        self::assertSame([], $this->fakeApi()->calls);

        // No usage event either — nothing was exported.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $event = $entityManager->getRepository(ExportEvent::class)->findOneBy([
            'variantId' => Uuid::fromString(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID),
        ]);
        self::assertNull($event);
    }

    public function testFreeFormPrintVariantCannotBePublishedToInstagramEither(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $data = $this->publish($client, [
            'platform' => 'instagram',
            'targetId' => FakeMetaGraphApi::PAGE_WITH_IG_ID,
        ], TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $data);
        self::assertSame([], $this->fakeApi()->calls);
    }

    public function testInstagramRequiresPageWithLinkedInstagramAccount(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $data = $this->publish($client, [
            'platform' => 'instagram',
            'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $data);
    }

    public function testUnknownTargetPageIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $this->publish($client, [
            'platform' => 'facebook',
            'targetId' => 'somebody-elses-page',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->fakeApi()->callsTo('publishPagePhoto'));
    }

    public function testExpiredTokenFlagsAccountForReconnect(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $this->fakeApi()->throwOnPublish = new FacebookTokenExpired('Error validating access token.', 190);

        $data = $this->publish($client, [
            'platform' => 'facebook',
            'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID,
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($data['reconnect']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $account = $entityManager->getRepository(SocialAccount::class)->findOneBy([
            'providerUserId' => TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
        ]);
        self::assertInstanceOf(SocialAccount::class, $account);
        self::assertTrue($account->needsReconnect);
    }

    public function testUserWithoutFacebookConnectionGetsConflict(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $data = $this->publish($client, [
            'platform' => 'facebook',
            'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID,
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($data['reconnect']);
    }

    public function testInvalidPlatformIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $this->publish($client, ['platform' => 'myspace', 'targetId' => 'x']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testUserWithoutVariantAccessGetsForbidden(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $this->publish($client, [
            'platform' => 'facebook',
            'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID,
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
