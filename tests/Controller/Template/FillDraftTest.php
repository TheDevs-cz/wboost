<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * "Uložit rozpracované": work in progress is saved as a DRAFT version without
 * any render (so an overflowing, un-exportable form still saves), the page's
 * own draft is overwritten in place, a loaded export never is, and exporting
 * with the draft loaded turns the draft into the export's version.
 *
 * @covers \WBoost\Web\Controller\Template\SaveTemplateVariantFillDraftController
 * @covers \WBoost\Web\Controller\TemplateGroup\SaveTemplateGroupFillDraftController
 * @covers \WBoost\Web\MessageHandler\Template\SaveTemplateFillDraftHandler
 * @covers \WBoost\Web\MessageHandler\Template\RecordTemplateExportVersionHandler
 */
final class FillDraftTest extends WebTestCase
{
    public function testVariantDraftSavesUpdatesInPlaceAndRendersTheHistoryChrome(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $token = $this->draftToken($client, $this->variantFillUrl());

        // A text far too long for its box: the export would be blocked
        // client-side, the save does not care.
        $client->request('POST', $this->variantDraftUrl(), [
            '_fill_draft_token' => $token,
            'draftVersionId' => '',
            'textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => str_repeat('Rozpracováno ', 200)],
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        $draftId = (string) $client->getResponse()->headers->get('X-Fill-Draft-Version');
        self::assertTrue(Uuid::isValid($draftId));

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="export-history-menu"', $content);
        self::assertStringNotContainsString('export-history-banner', $content);
        // The toggle names the draft — the only "what is loaded" signal.
        self::assertMatchesRegularExpression('~<span id="export-history-toggle-label"[^>]*>Rozpracováno \d~', $content);
        self::assertStringContainsString('?version=' . $draftId, $content);

        $draft = $this->soleVariantVersion();
        self::assertSame($draftId, $draft->id->toString());
        self::assertTrue($draft->draft);
        self::assertSame(0, $draft->exportCount);

        // Saving again from the same page overwrites THAT draft.
        $client->request('POST', $this->variantDraftUrl(), [
            '_fill_draft_token' => $token,
            'draftVersionId' => $draftId,
            'textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Druhé uložení'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($draftId, $client->getResponse()->headers->get('X-Fill-Draft-Version'));

        $resaved = $this->soleVariantVersion();
        self::assertTrue($resaved->draft);
        self::assertSame('Druhé uložení', $resaved->fillValues->toArray()['texts'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID]);

        // The listing's "naposledy exportováno" ignores drafts.
        $latest = self::getContainer()->get(GetExportVersions::class)->latestForTemplateVariants(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_1_ID));
        self::assertArrayNotHasKey(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, $latest);

        // The loaded draft is named on the toggle + fills the page's draft field.
        $crawler = $client->request('GET', $this->variantFillUrl() . '?version=' . $draftId);
        self::assertResponseIsSuccessful();
        self::assertSame($draftId, $crawler->filter('input[name="draftVersionId"][form="variant-fill-form"]')->attr('value'));
        self::assertStringStartsWith('Rozpracováno ', $crawler->filter('#export-history-toggle-label')->text());
        self::assertCount(0, $crawler->filter('#export-history-banner'));
    }

    public function testExportingTheLoadedDraftPromotesIt(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $token = $this->draftToken($client, $this->variantFillUrl());

        $values = ['textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Hotovo']];

        $client->request('POST', $this->variantDraftUrl(), ['_fill_draft_token' => $token, 'draftVersionId' => ''] + $values);
        $draftId = (string) $client->getResponse()->headers->get('X-Fill-Draft-Version');

        $client->request('POST', $this->variantDownloadUrl(), ['draftVersionId' => $draftId] + $values);
        self::assertResponseIsSuccessful();

        $promoted = $this->soleVariantVersion();
        self::assertSame($draftId, $promoted->id->toString());
        self::assertFalse($promoted->draft);
        self::assertSame(1, $promoted->exportCount);

        // Now it IS an export: the listing's "naposledy exportováno" sees it.
        $latest = self::getContainer()->get(GetExportVersions::class)->latestForTemplateVariants(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_1_ID));
        self::assertSame($draftId, $latest[TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]['versionId'] ?? null);

        // Saving from the page afterwards never overwrites the export: it
        // starts a new draft.
        $client->request('POST', $this->variantDraftUrl(), ['_fill_draft_token' => $token, 'draftVersionId' => $draftId] + $values);
        self::assertResponseIsSuccessful();
        self::assertNotSame($draftId, $client->getResponse()->headers->get('X-Fill-Draft-Version'));
        self::assertCount(2, $this->variantVersions());
    }

    public function testADraftMatchingAnEarlierExportMergesIntoIt(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $token = $this->draftToken($client, $this->variantFillUrl());

        $values = ['textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Už jednou exportováno']];

        $client->request('POST', $this->variantDownloadUrl(), $values);
        self::assertResponseIsSuccessful();
        $exportId = $this->soleVariantVersion()->id->toString();

        $client->request('POST', $this->variantDraftUrl(), ['_fill_draft_token' => $token, 'draftVersionId' => $exportId] + $values);
        $draftId = (string) $client->getResponse()->headers->get('X-Fill-Draft-Version');
        self::assertNotSame($exportId, $draftId, 'a loaded export is never overwritten by a save');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $draft = $entityManager->find(TemplateExportVersion::class, Uuid::fromString($draftId));
        self::assertInstanceOf(TemplateExportVersion::class, $draft);
        $draft->rename('Finále');
        $entityManager->flush();

        $client->request('POST', $this->variantDownloadUrl(), ['draftVersionId' => $draftId] + $values);
        self::assertResponseIsSuccessful();

        $merged = $this->soleVariantVersion();
        self::assertSame($exportId, $merged->id->toString());
        self::assertSame(2, $merged->exportCount);
        self::assertSame('Finále', $merged->name);
    }

    public function testDraftSaveRequiresAValidToken(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', $this->variantDraftUrl(), ['_fill_draft_token' => 'nope', 'textValues' => []]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->variantVersions());
    }

    public function testGroupDraftKeepsPlacementsAndTheZipExportPromotesIt(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $groupFillUrl = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill';
        $token = $this->draftToken($client, $groupFillUrl);

        $values = [
            'textValues' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Skupinový koncept'],
            'imagePlacements' => [
                TestDataFixture::GROUPED_PRESET_VARIANT_ID => [
                    TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['scale' => '1.5'],
                ],
            ],
        ];

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill-draft', ['_fill_draft_token' => $token, 'draftVersionId' => ''] + $values);
        self::assertResponseIsSuccessful();
        $draftId = (string) $client->getResponse()->headers->get('X-Fill-Draft-Version');

        $draft = $this->soleGroupVersion();
        self::assertTrue($draft->draft);
        self::assertSame(
            ['scale' => 1.5],
            $draft->fillValues->toArray()['placements'][TestDataFixture::GROUPED_PRESET_VARIANT_ID][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID],
        );

        $crawler = $client->request('GET', $groupFillUrl . '?version=' . $draftId);
        self::assertResponseIsSuccessful();
        self::assertSame($draftId, $crawler->filter('form.fill-form input[name="draftVersionId"]')->attr('value'));
        self::assertSame('Skupinový koncept', $crawler->filter(sprintf('input[name="textValues[%s]"]', TestDataFixture::GROUP_SHARED_INPUT_ID))->attr('value'));

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', ['draftVersionId' => $draftId] + $values);
        self::assertResponseIsSuccessful();

        $promoted = $this->soleGroupVersion();
        self::assertSame($draftId, $promoted->id->toString());
        self::assertFalse($promoted->draft);
    }

    private function draftToken(KernelBrowser $client, string $fillUrl): string
    {
        $crawler = $client->request('GET', $fillUrl);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="_fill_draft_token"]')->attr('value');
    }

    private function variantFillUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export';
    }

    private function variantDraftUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/fill-draft';
    }

    private function variantDownloadUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download';
    }

    /** @return list<TemplateExportVersion> */
    private function variantVersions(): array
    {
        /** @var list<TemplateExportVersion> */
        return self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(TemplateExportVersion::class)
            ->findBy(['variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]);
    }

    private function soleVariantVersion(): TemplateExportVersion
    {
        $versions = $this->variantVersions();
        self::assertCount(1, $versions);

        return $versions[0];
    }

    private function soleGroupVersion(): TemplateExportVersion
    {
        /** @var list<TemplateExportVersion> $versions */
        $versions = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(TemplateExportVersion::class)
            ->findBy(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);
        self::assertCount(1, $versions);

        return $versions[0];
    }
}
