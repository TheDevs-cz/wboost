<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\FacebookPage\FacebookPageApiInterface;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Services\Meta\MetaGraphApiInterface;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeFacebookPageApi;
use WBoost\Web\Tests\Fakes\FakeInstagramApi;
use WBoost\Web\Tests\Fakes\FakeMetaGraphApi;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\SocialPlatform;

/**
 * The publish endpoint `/template-variant/{id}/publish`: the personal
 * Facebook link (targetId), the project Instagram account and the project
 * Facebook Page (`platform=facebook` without targetId). Every format can be
 * published.
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

    /**
     * Connects PROJECT_1 to the fake Instagram account (as the connect flow
     * would) — a never-expiring token unless `$expiresAt` is given.
     */
    private function connectInstagram(null|string $expiresAt = null): ProjectSocialAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);

        $account = new ProjectSocialAccount(
            Uuid::uuid7(),
            $project,
            SocialPlatform::Instagram,
            FakeInstagramApi::IG_USER_ID,
            FakeInstagramApi::APP_SCOPED_ID,
            FakeInstagramApi::USERNAME,
            'Kavárna Test',
            'BUSINESS',
            self::getContainer()->get(TokenCrypto::class)->encrypt('ig-long-token'),
            $expiresAt !== null ? new \DateTimeImmutable($expiresAt) : null,
            [],
            null,
            new \DateTimeImmutable('-10 days'),
        );
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }

    private function instagramApi(): FakeInstagramApi
    {
        $api = self::getContainer()->get(InstagramApiInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeInstagramApi::class, $api);

        return $api;
    }

    public function testPublishToProjectInstagramUploadsJpegAndCleansUp(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->connectInstagram();

        $data = $this->publish($client, [
            'platform' => 'instagram',
            // A posted destination is ignored — the project decides.
            'targetId' => 'somebody-elses-account',
            'caption' => 'IG post #wboost',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['ok']);
        self::assertSame(FakeInstagramApi::MEDIA_ID, $data['postId']);
        self::assertSame(FakeInstagramApi::PERMALINK, $data['permalink']);
        self::assertSame('feed', $data['kind']);

        $api = $this->instagramApi();
        $containerCalls = $api->callsTo('createImageContainer');
        self::assertCount(1, $containerCalls);
        self::assertSame(FakeInstagramApi::IG_USER_ID, $containerCalls[0]['args']['igUserId']);
        self::assertSame('ig-long-token', $containerCalls[0]['args']['accessToken']);
        self::assertSame('IG post #wboost', $containerCalls[0]['args']['caption']);
        self::assertFalse($containerCalls[0]['args']['story']);
        // Instagram fetches the image itself: a public JPEG URL, not bytes.
        self::assertIsString($containerCalls[0]['args']['imageUrl']);
        self::assertStringContainsString('social-publish/', $containerCalls[0]['args']['imageUrl']);
        self::assertStringEndsWith('.jpg', $containerCalls[0]['args']['imageUrl']);
        self::assertCount(1, $api->callsTo('publishContainer'));
        self::assertSame('ig-long-token', $containerCalls[0]['args']['accessToken']);
        // The Facebook side is never touched.
        self::assertSame([], $this->fakeApi()->calls);

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

    public function testNineBySixteenVariantGoesOutAsStoryWithoutCaption(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->connectInstagram();

        $data = $this->publish($client, [
            'platform' => 'instagram',
            'caption' => 'ignored on stories',
        ], TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID);

        self::assertResponseIsSuccessful();
        self::assertSame('story', $data['kind']);
        $containerCalls = $this->instagramApi()->callsTo('createImageContainer');
        self::assertCount(1, $containerCalls);
        self::assertTrue($containerCalls[0]['args']['story']);
        self::assertNull($containerCalls[0]['args']['caption']);
    }

    public function testSharedMemberMayPublishToTheProjectsInstagram(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $this->connectInstagram();

        $data = $this->publish($client, ['platform' => 'instagram', 'caption' => 'x']);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['ok']);
    }

    public function testProjectWithoutInstagramGetsConflict(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $data = $this->publish($client, ['platform' => 'instagram', 'caption' => 'x']);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($data['reconnect']);
        self::assertSame([], $this->instagramApi()->calls);
    }

    public function testExpiredTokenAsksForReconnectWithoutCallingInstagram(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->connectInstagram('-1 day');

        $data = $this->publish($client, ['platform' => 'instagram', 'caption' => 'x']);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($data['reconnect']);
        self::assertSame([], $this->instagramApi()->callsTo('createImageContainer'));
    }

    public function testRejectedInstagramTokenFlagsTheConnection(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $account = $this->connectInstagram();
        $this->instagramApi()->throwOnPublish = new InstagramTokenExpired('Error validating access token.', 190);

        $data = $this->publish($client, ['platform' => 'instagram', 'caption' => 'x']);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($data['reconnect']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->find(ProjectSocialAccount::class, $account->id);
        self::assertInstanceOf(ProjectSocialAccount::class, $stored);
        self::assertTrue($stored->needsReconnect);
    }

    public function testOverlongCaptionIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->connectInstagram();

        $this->publish($client, ['platform' => 'instagram', 'caption' => str_repeat('a', 2201)]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->instagramApi()->calls);
    }

    public function testFreeFormVariantPublishesToFacebook(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        // Every format can be published since 2026-11-27 — Facebook takes any size.
        $data = $this->publish($client, [
            'platform' => 'facebook',
            'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID,
            'caption' => 'Free-form',
        ], TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['ok']);
        self::assertNotSame([], $this->fakeApi()->calls);
    }

    public function testFreeFormPrintVariantPublishesToInstagramAsFeedPost(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->connectInstagram();

        // A print size outside Instagram's feed ratios is padded on the way out
        // (InstagramImageFit), never refused.
        $data = $this->publish($client, [
            'platform' => 'instagram',
            'caption' => 'A4 leták',
        ], TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        self::assertResponseIsSuccessful();
        self::assertSame('feed', $data['kind']);
        self::assertNotSame([], $this->instagramApi()->calls);
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

    private function facebookPageApi(): FakeFacebookPageApi
    {
        $api = self::getContainer()->get(FacebookPageApiInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeFacebookPageApi::class, $api);

        return $api;
    }

    private function connectFacebookPage(): ProjectSocialAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);

        $account = new ProjectSocialAccount(
            Uuid::uuid7(),
            $project,
            SocialPlatform::Facebook,
            FakeFacebookPageApi::PAGE_ID,
            FakeFacebookPageApi::OWNER_ID,
            null,
            FakeFacebookPageApi::PAGE_NAME,
            null,
            self::getContainer()->get(TokenCrypto::class)->encrypt('fb-page-token'),
            null,
            [],
            null,
            new \DateTimeImmutable('-10 days'),
        );
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }

    public function testPublishesToTheProjectFacebookPage(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $this->connectFacebookPage();
        $caption = str_repeat('Dlouhý text. ', 300); // > 2 200 — fine on Facebook

        $data = $this->publish($client, ['platform' => 'facebook', 'caption' => $caption], TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        self::assertResponseIsSuccessful();
        self::assertSame('facebook', $data['platform']);
        self::assertSame(FakeFacebookPageApi::POST_ID, $data['postId']);
        self::assertSame(FakeFacebookPageApi::PERMALINK, $data['permalink']);
        self::assertSame(FakeFacebookPageApi::PAGE_NAME, $data['account']);

        $call = $this->facebookPageApi()->callsTo('publishPhoto')[0]['args'];
        self::assertSame(FakeFacebookPageApi::PAGE_ID, $call['pageId']);
        self::assertSame('fb-page-token', $call['pageAccessToken']);
        self::assertSame(trim($caption), $call['caption']);
        self::assertSame([], $this->fakeApi()->calls, 'the personal Facebook link is not used');

        $event = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportEvent::class)->findOneBy([
            'variantId' => Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID),
        ]);
        self::assertNotNull($event);
        self::assertSame(ExportChannel::Facebook, $event->channel);
    }

    public function testProjectFacebookPageNotConnected(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $data = $this->publish($client, ['platform' => 'facebook', 'caption' => 'x']);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($data['reconnect']);
    }

    public function testRejectedPageTokenFlagsTheConnection(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $account = $this->connectFacebookPage();
        $this->facebookPageApi()->throwOnPublish = new FacebookPageTokenExpired('Session has expired.', 190);

        $data = $this->publish($client, ['platform' => 'facebook', 'caption' => 'x']);

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($data['reconnect']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(ProjectSocialAccount::class, $account->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->needsReconnect);
    }
}
