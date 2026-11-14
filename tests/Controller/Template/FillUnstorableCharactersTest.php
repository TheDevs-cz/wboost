<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The web fill pages are lenient: a value a JSONB column cannot take — the
 * character U+0000, bytes that are not UTF-8 (a form post may hold both, a
 * browser's own form never does) — is cleaned on the way into the stored
 * version instead of refusing the user's export. Until 2026-11-14 the
 * download answered 200 while its version was silently not recorded, and
 * "Uložit rozpracované" was a 500.
 *
 * @covers \WBoost\Web\Value\ExportFillValues
 * @covers \WBoost\Web\Services\Template\FillValueGuard
 * @covers \WBoost\Web\Controller\Template\TemplateVariantDownloadController
 * @covers \WBoost\Web\Controller\Template\SaveTemplateVariantFillDraftController
 * @covers \WBoost\Web\Controller\TemplateGroup\SaveTemplateGroupFillDraftController
 */
final class FillUnstorableCharactersTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string HEADLINE = TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID;
    private const string VARIANT_URL = '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID;
    private const string GROUP_URL = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID;

    public function testDownloadRecordsItsVersionWithoutTheCharacter(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', self::VARIANT_URL . '/download', [
            'textValues' => [self::HEADLINE => "Ver\0ze", "x\0y" => "a\xffb"],
        ]);
        self::assertResponseIsSuccessful();

        $versions = $this->versions(['variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]);
        self::assertCount(1, $versions, 'the export is in the history');
        self::assertSame('Verze', $versions[0]->fillValues->texts[self::HEADLINE] ?? null);
        self::assertSame('a?b', $versions[0]->fillValues->texts['xy'] ?? null);

        // The cleaned fill is the same version as the clean one.
        $client->request('POST', self::VARIANT_URL . '/download', ['textValues' => [self::HEADLINE => 'Verze', 'xy' => 'a?b']]);
        self::assertResponseIsSuccessful();
        $versions = $this->versions(['variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]);
        self::assertCount(1, $versions);
        self::assertSame(2, $versions[0]->exportCount);
    }

    public function testVariantDraftSavesWithoutTheCharacter(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $token = $this->draftToken($client, self::VARIANT_URL . '/export');

        $client->request('POST', self::VARIANT_URL . '/fill-draft', [
            '_fill_draft_token' => $token,
            'draftVersionId' => '',
            'textValues' => [self::HEADLINE => "Rozpra\0cováno \xf0\x9f"],
            'fontValues' => [self::HEADLINE => "Out\0fit"],
            'shapeColorValues' => ["s\0" => "#ff\x000000"],
            'images' => [self::HEADLINE => ['imageId' => "a\0b"]],
        ]);
        self::assertResponseIsSuccessful();

        $versions = $this->versions(['variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]);
        self::assertCount(1, $versions);
        self::assertTrue($versions[0]->draft);
        $fill = $versions[0]->fillValues;
        self::assertSame('Rozpracováno ?', $fill->texts[self::HEADLINE] ?? null);
        self::assertSame('Outfit', $fill->fonts[self::HEADLINE] ?? null);
        self::assertSame(['imageId' => 'ab'], $fill->images[self::HEADLINE] ?? null);
        self::assertStringNotContainsString('\u0000', (string) json_encode($fill->toArray()));
    }

    public function testGroupDraftSavesWithoutTheCharacter(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $token = $this->draftToken($client, self::GROUP_URL . '/fill');

        $client->request('POST', self::GROUP_URL . '/fill-draft', [
            '_fill_draft_token' => $token,
            'draftVersionId' => '',
            'textValues' => [TestDataFixture::GROUP_SHARED_INPUT_ID => "Skupinový\0 koncept"],
        ]);
        self::assertResponseIsSuccessful();

        $versions = $this->versions(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);
        self::assertCount(1, $versions);
        self::assertSame('Skupinový koncept', $versions[0]->fillValues->texts[TestDataFixture::GROUP_SHARED_INPUT_ID] ?? null);
    }

    /**
     * The live preview stores nothing: the component renders with the value
     * as it came.
     */
    public function testTheLivePreviewRendersWithTheCharacter(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $component = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $variant],
            client: $client,
        );
        $component->set('textValues.' . TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, "Na\0hled");

        self::assertStringContainsString('hled', (string) $component->render());
    }

    private function draftToken(KernelBrowser $client, string $fillUrl): string
    {
        $crawler = $client->request('GET', $fillUrl);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="_fill_draft_token"]')->attr('value');
    }

    /**
     * @param array<string, string> $criteria
     * @return list<TemplateExportVersion>
     */
    private function versions(array $criteria): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        /** @var list<TemplateExportVersion> */
        return $entityManager->getRepository(TemplateExportVersion::class)->findBy($criteria);
    }
}
