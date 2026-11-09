<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\RowMergeFixtures;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * A row MERGED by "Sloučit do opakovaného řádku" (container-rows P6) over the
 * REST API: a caller that keeps sending the OLD hand-numbered ids ("H -
 * Střelec 3") is served exactly as before the merge — the value lands in its
 * row, the row shows (even after the designer lowered the default to 1), the
 * version records it under the same id and the history answers it as a row.
 * The listing exposes the old ids as the merged row's slot ids (P5's
 * `repeatableRows[].slots`), never as plain inputs.
 *
 * Fixture: the prod "Výsledek" (1350) with both scorer columns merged by the
 * real editor module, default lowered to 1.
 *
 * @covers \WBoost\Web\Services\Template\ApiRepeatableRows
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Services\Template\ExportVersionApiView
 */
final class TemplateRowMergeApiTest extends ApiTestCase
{
    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;

    public function testTheListingShowsTheOldIdsAsTheMergedRowsSlots(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeMerged();

        $variant = $this->listedVariant($client, $token);
        $listed = $variant['repeatableRows'] ?? null;
        self::assertIsArray($listed);
        $rows = array_column($listed, null, 'containerId');
        $away = $rows[RowMergeFixtures::VYSLEDEK_H_ROW] ?? null;
        self::assertIsArray($away);
        $slots = $away['slots'] ?? null;
        self::assertIsArray($slots);
        self::assertCount(4, $slots);
        foreach (array_values($slots) as $k => $slot) {
            self::assertIsArray($slot);
            $ids = $slot['ids'] ?? null;
            self::assertIsArray($ids);
            self::assertSame(RowMergeFixtures::VYSLEDEK_H[$k] ?? null, $ids[RowMergeFixtures::VYSLEDEK_H[0]] ?? null, 'row ' . ($k + 1) . ' keeps its old id');
        }

        $inputs = $variant['inputs'] ?? null;
        self::assertIsArray($inputs);
        $inputIds = array_column($inputs, 'id');
        self::assertContains(RowMergeFixtures::VYSLEDEK_H[0], $inputIds);
        self::assertNotContains(RowMergeFixtures::VYSLEDEK_H[2], $inputIds, 'old rows are slots now, not inputs');
    }

    public function testACallerSendingOldIdsGetsItsRowsAndItsHistoryBack(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeMerged();

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['inputs' => [
            RowMergeFixtures::VYSLEDEK_D[1] => '12" Novák',
            RowMergeFixtures::VYSLEDEK_H[2] => ['value' => '80" Dvořák'],
            RowMergeFixtures::VYSLEDEK_D[3] => ['hide' => true],
        ]]);
        $this->assertResponseIsSuccessful();

        $renderer = $this->renderer($client);
        $call = $renderer->calls[count($renderer->calls) - 1];
        self::assertSame('12" Novák', $call['texts'][RowMergeFixtures::VYSLEDEK_D[1]] ?? null);
        self::assertSame('80" Dvořák', $call['texts'][RowMergeFixtures::VYSLEDEK_H[2]] ?? null);

        // Default 1, but the body addresses home row 4 (a hide) and away row 3.
        $hides = $renderer->hideCalls[count($renderer->hideCalls) - 1];
        self::assertEquals([RowMergeFixtures::VYSLEDEK_D_ROW => 4, RowMergeFixtures::VYSLEDEK_H_ROW => 3], $hides['rowCounts']);
        self::assertTrue($hides['effectiveHidden'][RowMergeFixtures::VYSLEDEK_D[3]] ?? false, 'the old hide still hides');
        self::assertArrayNotHasKey(RowMergeFixtures::VYSLEDEK_H[2], $hides['effectiveHidden']);
        self::assertTrue($hides['effectiveHidden'][RowMergeFixtures::VYSLEDEK_H[3]] ?? false, 'away row 4 is unused');

        $version = $this->variantVersion();
        self::assertEquals([RowMergeFixtures::VYSLEDEK_D_ROW => 4, RowMergeFixtures::VYSLEDEK_H_ROW => 3], $version->fillValues->rows, 'the recorded count is the rendered one');
        self::assertSame('80" Dvořák', $version->fillValues->texts[RowMergeFixtures::VYSLEDEK_H[2]] ?? null, 'stored under the old id');

        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        $fill = $detail['fill'] ?? null;
        self::assertIsArray($fill);
        $folded = $fill['rows'] ?? null;
        self::assertIsArray($folded);
        $away = $folded[RowMergeFixtures::VYSLEDEK_H_ROW] ?? null;
        self::assertIsArray($away);
        self::assertCount(3, $away);
        $third = $away[2] ?? null;
        self::assertIsArray($third);
        self::assertSame('80" Dvořák', $third[RowMergeFixtures::VYSLEDEK_H[0]] ?? null, 'answered as row 3, keyed by the template id');
    }

    private function makeMerged(): void
    {
        $variant = self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString(self::VARIANT));
        ContainerFixtures::applyRaw($variant, RowMergeFixtures::withDefault(RowMergeFixtures::vysledekMerged(), 1));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function listedVariant(Client $client, string $token): array
    {
        $body = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        foreach ($body as $template) {
            self::assertIsArray($template);
            $variants = $template['variants'] ?? null;
            self::assertIsArray($variants);
            foreach ($variants as $row) {
                self::assertIsArray($row);
                if ($row['id'] === self::VARIANT) {
                    /** @var array<string, mixed> $row */
                    return $row;
                }
            }
        }

        self::fail('variant not listed');
    }

    private function variantVersion(): TemplateExportVersion
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $version = $em->getRepository(TemplateExportVersion::class)->findOneBy(['variant' => self::VARIANT]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);

        return $version;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(Client $client, string $token, string $url, array $body): ResponseInterface
    {
        return $client->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
    }

    private function token(Client $client): string
    {
        return TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET);
    }

    private function renderer(Client $client): FakeTemplateVariantImageRenderer
    {
        $renderer = $client->getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
