<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportChannel;

/**
 * Export versioning ("Historie exportů") on the single-variant surface: a
 * successful web download snapshots the posted fill values as a version, an
 * identical re-export bumps that version instead of duplicating it, and
 * `?version=<id>` on the fill page loads the snapshot back (banner + history
 * dropdown), silently ignoring ids that don't belong there.
 *
 * @covers \WBoost\Web\Controller\Template\TemplateVariantDownloadController
 * @covers \WBoost\Web\Controller\Template\TemplateVariantExportController
 * @covers \WBoost\Web\Services\Template\RecordExportVersion
 * @covers \WBoost\Web\Query\GetExportVersions
 */
final class TemplateVariantExportVersionTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    /**
     * The seed props travel INTO the deferred Live component and come out as
     * markup: seeded texts land in the mirror inputs, seeded image state
     * pre-fills the hidden `images[…]` fields and the hide checkbox — the
     * page GET alone can't show this (the component renders via its own Live
     * request), so render the component directly.
     */
    public function testComponentRendersSeededValuesIntoTheFillMarkup(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: [
                'variant' => $variant,
                'textValues' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Seeded headline'],
                'hiddenValues' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID => true],
                'seedImageValues' => [
                    TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => [
                        'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                        'url' => '/uploads/fixtures/in-allowed.png',
                        'scale' => 1.5,
                        'offsetX' => 12.5,
                    ],
                ],
            ],
            client: $client,
        );

        $html = (string) $testComponent->render();

        self::assertStringContainsString('Seeded headline', $html);
        self::assertStringContainsString(
            sprintf('name="images[%s][imageId]"', TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID),
            $html,
        );
        self::assertStringContainsString(sprintf('value="%s"', TestDataFixture::FILE_IN_ALLOWED_ID), $html);
        self::assertStringContainsString('data-field="scale" value="1.5"', $html);
        self::assertStringContainsString('data-field="offsetX" value="12.5"', $html);
        // The seed rides the placeholder payload for the JS restore.
        self::assertStringContainsString('&quot;seed&quot;', $html);
    }

    public function testDownloadRecordsAVersionAndAnIdenticalRepostBumpsIt(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $payload = [
            'textValues' => [
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Verze první',
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID => '',
            ],
            'hiddenValues' => [
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID => '1',
            ],
        ];

        $client->request('POST', $this->downloadUrl(), $payload);
        self::assertResponseIsSuccessful();

        $version = $this->soleVersion();
        self::assertSame(ExportChannel::Web, $version->channel);
        self::assertSame(1, $version->exportCount);
        self::assertNotNull($version->exportedBy);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $version->exportedBy->email);
        self::assertSame(
            [
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Verze první',
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID => '',
            ],
            $version->fillValues->toArray()['texts'],
        );
        self::assertSame(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID],
            $version->fillValues->toArray()['hidden'],
        );

        // The identical fill again: no new history entry, the row bumps.
        $client->request('POST', $this->downloadUrl(), $payload);
        self::assertResponseIsSuccessful();

        $bumped = $this->soleVersion();
        self::assertSame($version->id->toString(), $bumped->id->toString());
        self::assertSame(2, $bumped->exportCount);
    }

    public function testFillPageLoadsAVersionAndIgnoresForeignOrUnknownIds(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', $this->downloadUrl(), [
            'textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Načtená verze'],
        ]);
        self::assertResponseIsSuccessful();
        $version = $this->soleVersion();

        // Plain visit: history dropdown lists the version, no banner.
        $crawler = $client->request('GET', $this->fillPageUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Historie exportů', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Formulář je předvyplněný', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter(sprintf('a[href*="version=%s"]', $version->id->toString())));

        // Loading the version shows the banner (the component seeds arrive via
        // its deferred Live request, so the banner is the page-level signal).
        $client->request('GET', $this->fillPageUrl() . '?version=' . $version->id->toString());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Formulář je předvyplněný', (string) $client->getResponse()->getContent());

        // A version of ANOTHER variant — and plain garbage — must be ignored.
        $client->request('POST', '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download', [
            'textValues' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Cizí verze'],
        ]);
        self::assertResponseIsSuccessful();
        $foreign = $this->versionForVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        foreach ([$foreign->id->toString(), 'not-a-uuid', Uuid::uuid4()->toString()] as $ignored) {
            $client->request('GET', $this->fillPageUrl() . '?version=' . $ignored);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Formulář je předvyplněný', (string) $client->getResponse()->getContent());
        }
    }

    private function downloadUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download';
    }

    private function fillPageUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export';
    }

    private function soleVersion(): TemplateExportVersion
    {
        return $this->versionForVariant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);
    }

    private function versionForVariant(string $variantId): TemplateExportVersion
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId]);

        self::assertCount(1, $versions);

        return $versions[0];
    }
}
