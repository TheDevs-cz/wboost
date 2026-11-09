<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;

/**
 * Container-rows P4 — the row UI on the three fill pages: the container
 * sheet's "Řádek n" sections with ↑ / ↓ / −, "+ Přidat řádek", the canvas
 * "+ Řádek" chip, the client payload (`data-fill-rows`) and the echo, which
 * now covers a tree holding rows (the painters hide the unused ones).
 *
 * Fixture: "Výsledky turnaje" re-authored with ONE repeatable row (max 5,
 * default 2) written onto real fixture variants.
 *
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 * @covers \WBoost\Web\Services\SocialNetwork\FillRepeatableRows
 * @covers \WBoost\Web\Services\SocialNetwork\FillContainerSheets
 */
final class RepeatableRowsFillUiTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;

    public function testTheSinglePageSheetHasRowControlsAndThePayload(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->makeRepeatable(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        );
        $crawler = new Crawler((string) $testComponent->render());

        $this->assertRowControls($crawler, self::ROW);

        // The client payload: every role's id in every slot + the count.
        $payload = $this->payload($crawler);
        self::assertSame(2, $payload[self::ROW]['count']);
        self::assertSame(5, $payload[self::ROW]['max']);
        self::assertSame(RepeatableRows::slotId(self::ROW, self::SOUPER, 4), $payload[self::ROW]['roles'][self::SOUPER][4] ?? null);

        // "Všechna pole" keeps a row's cards together, row by row.
        $cards = $crawler->filter('.fill-popovers > .fill-popover[data-inputid]')->each(static fn (Crawler $card): string => (string) $card->attr('data-inputid'));
        $first = array_search(RepeatableRows::slotId(self::ROW, self::SOUPER, 1), $cards, true);
        self::assertIsInt($first);
        self::assertSame(RepeatableRows::slotId(self::ROW, ContainerFixtures::TURNAJ_SKORE_1, 1), $cards[$first - 1] ?? null, 'row 2: Skóre, then Soupeř');

        // The echo covers the rows now; the painter gets the slot map.
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        self::assertContains(RepeatableRows::slotId(self::ROW, self::SOUPER, 3), $component->echoCapableIds());
        $echo = $component->echoPayload();
        self::assertNotNull($echo);
        self::assertCount(5, $echo['repeatRows'][self::ROW]['slots'] ?? []);
    }

    public function testADesignWithoutRowsRendersNoRowChrome(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        );
        $html = (string) $testComponent->render();

        self::assertStringNotContainsString('data-fill-rows', $html);
        self::assertStringNotContainsString('data-row-chip', $html);
        self::assertStringNotContainsString('data-fill-sheet-row-add', $html);
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        $echo = $component->echoPayload();
        self::assertTrue($echo === null || !array_key_exists('repeatRows', $echo), 'every pre-rows echo payload keeps its exact shape');
    }

    public function testTheGroupPageSharesOneRowPayload(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->makeRepeatable(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        $this->makeRepeatable(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();

        $this->assertRowControls($crawler, self::ROW, surfaces: 2);
        self::assertSame([self::ROW], array_keys($this->payload($crawler)), 'ONE row, shared by every size');
    }

    public function testTheDocumentPageHasOneRowPerPageInFillKeySpace(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $second = TemplateDocumentTestData::a4Template($em, 'Strana B');
        $this->makeRepeatable($first['variant']->toString());
        $this->makeRepeatable($second['variant']->toString());
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']]);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();

        $firstRow = $this->key($first['template'], self::ROW);
        $secondRow = $this->key($second['template'], self::ROW);
        $payload = $this->payload($crawler);
        self::assertEqualsCanonicalizing([$firstRow, $secondRow], array_keys($payload));
        self::assertSame(
            $this->key($first['template'], RepeatableRows::slotId(self::ROW, self::SOUPER, 2)),
            $payload[$firstRow]['roles'][$this->key($first['template'], self::SOUPER)][2] ?? null,
            'roles are fill keys too',
        );
        $this->assertRowControls($crawler, $firstRow);
        $this->assertRowControls($crawler, $secondRow);
    }

    private function assertRowControls(Crawler $crawler, string $rowId, int $surfaces = 1): void
    {
        $sections = $crawler->filter(sprintf('.fill-sheet__section--row[data-row="%s"]', $rowId));
        self::assertCount(5, $sections, 'one "Řádek n" section per materialised row');
        self::assertSame(['0', '1', '2', '3', '4'], $sections->each(static fn (Crawler $s): string => (string) $s->attr('data-slot')));
        self::assertStringContainsString('Řádek 3', $sections->eq(2)->text());
        foreach (['moveRowUp', 'moveRowDown', 'removeRow'] as $action) {
            self::assertCount(5, $sections->filter(sprintf('button[data-action="variant-fill-overlay#%s"][data-variant-fill-overlay-row-param="%s"]', $action, $rowId)), $action);
        }
        self::assertCount(1, $crawler->filter(sprintf('[data-fill-sheet-row-add][data-row-id="%s"] button[data-action="variant-fill-overlay#addRow"]', $rowId)));
        self::assertCount($surfaces, $crawler->filter(sprintf('button.fill-row-chip[data-row-chip="%s"][hidden]', $rowId)), 'one canvas chip per surface, shown by the overlay');

        // The sheet's entries list the add control right after the last row.
        $heads = $crawler->filter('[data-fill-sheet]');
        $found = false;
        foreach ($heads as $node) {
            /** @var list<array{kind: string, id: string}> $entries */
            $entries = json_decode((string) (new Crawler($node))->attr('data-sheet-entries'), true, flags: JSON_THROW_ON_ERROR);
            $kinds = array_column($entries, 'kind');
            $at = array_search('rowAdd', $kinds, true);
            if ($at !== false && $entries[$at]['id'] === $rowId) {
                $found = true;
                self::assertSame('field', $kinds[$at - 1]);
            }
        }
        self::assertTrue($found);
    }

    /**
     * @return array<string, array{count: int, max: int, roles: array<string, list<string>>}>
     */
    private function payload(Crawler $crawler): array
    {
        $panel = $crawler->filter('.fill-popovers[data-fill-rows]');
        self::assertCount(1, $panel);
        /** @var array<string, array{count: int, max: int, roles: array<string, list<string>>}> $payload */
        $payload = json_decode((string) $panel->attr('data-fill-rows'), true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function makeRepeatable(string $variantId): void
    {
        ContainerFixtures::applyRaw($this->variant($variantId), ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function key(UuidInterface $templateId, string $id): string
    {
        return Uuid::uuid5($templateId->toString(), $id)->toString();
    }

    private function variant(string $variantId): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));
    }
}
