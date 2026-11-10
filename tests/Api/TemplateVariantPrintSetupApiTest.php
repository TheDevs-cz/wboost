<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * `PATCH /api/template-variants/{id}/print-setup` — bleed ("spadávka") + crop
 * marks of a STANDALONE print variant: the web "Tiskové nastavení" command,
 * validated by the same rule (and codes) as the document print setup.
 *
 * @covers \WBoost\Web\Api\Templates\TemplateVariantPrintSetupProcessor
 * @covers \WBoost\Web\Services\Template\VariantPrintSetup
 */
final class TemplateVariantPrintSetupApiTest extends ApiTestCase
{
    public function testSetsCropMarksAndReportsTheFixedBleed(): void
    {
        [$client, $headers] = $this->client();

        $response = $this->patch($client, $headers, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, ['cropMarks' => true]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $body = $response->toArray();
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, $body['id']);
        self::assertTrue($body['printFormat']);
        self::assertEquals(3, $body['bleedMm']);
        self::assertSame(35, $body['bleedPx'], '3 mm at 300 DPI.');
        self::assertTrue($body['cropMarks']);

        $marksOff = $this->patch($client, $headers, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, ['cropMarks' => false])->toArray();
        self::assertEquals(3, $marksOff['bleedMm']);
        self::assertFalse($marksOff['cropMarks']);

        /** @var list<array{id: string, variants: list<array{id: string, bleedMm: float|int, cropMarks: bool}>}> $templates */
        $templates = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', ['headers' => $headers])->toArray();
        $variants = array_column(array_merge(...array_column($templates, 'variants')), null, 'id');
        self::assertEquals(3, $variants[TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]['bleedMm']);
        self::assertFalse($variants[TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]['cropMarks']);
    }

    /**
     * `bleedMm` is no longer an input: whatever an older client still sends
     * is ignored without an error and without touching the crop marks.
     */
    public function testARetiredBleedIsIgnored(): void
    {
        [$client, $headers] = $this->client();
        $variant = TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID;

        $this->patch($client, $headers, $variant, ['cropMarks' => true]);

        foreach ([0, 5, 25, -1, 'lots', null] as $bleed) {
            $response = $this->patch($client, $headers, $variant, ['bleedMm' => $bleed]);
            self::assertSame(200, $response->getStatusCode(), json_encode($bleed, JSON_THROW_ON_ERROR));
            self::assertEquals(3, $response->toArray()['bleedMm']);
            self::assertTrue($response->toArray()['cropMarks'], 'A lone bleedMm changes nothing.');
        }
    }

    public function testRefusalsAreStructured(): void
    {
        [$client, $headers] = $this->client();
        $variant = TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID;

        foreach ([[['cropMarks' => 'yes'], 'invalid_crop_marks'], [[], 'missing_print_setup']] as [$body, $code]) {
            $refused = $this->patch($client, $headers, $variant, $body);
            self::assertSame(400, $refused->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
            self::assertSame($code, $refused->toArray(false)['code']);
        }

        $screen = $this->patch($client, $headers, TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, ['cropMarks' => false]);
        self::assertSame(400, $screen->getStatusCode());
        self::assertSame('print_setup_not_available', $screen->toArray(false)['code']);

        self::assertSame(404, $this->patch($client, $headers, Uuid::uuid7()->toString(), ['cropMarks' => true])->getStatusCode());

        $unchanged = $this->em()->find(TemplateVariant::class, Uuid::fromString($variant));
        self::assertInstanceOf(TemplateVariant::class, $unchanged);
        self::assertFalse($unchanged->dimension->cropMarks, 'No refusal wrote anything.');
    }

    /**
     * A document page follows its document (one PDF never mixes print
     * setups) — it is changed through the document, not the page variant.
     */
    public function testDocumentPagesAreChangedThroughTheirDocument(): void
    {
        [$client, $headers] = $this->client();
        $page = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $documentId = TemplateDocumentTestData::document($this->em(), self::getContainer()->get(MessageBusInterface::class), [$page['template']]);

        $refused = $this->patch($client, $headers, $page['variant']->toString(), ['cropMarks' => true]);
        self::assertSame(400, $refused->getStatusCode());
        $body = $refused->toArray(false);
        self::assertSame('document_page', $body['code']);
        self::assertSame($documentId->toString(), $body['documentId']);
    }

    /**
     * Like the web form: a grouped variant's print setup is its own (every
     * group dimension carries its own crop marks) — nothing propagates.
     */
    public function testGroupedVariantKeepsItsOwnPrintSetup(): void
    {
        [$client, $headers] = $this->client();

        $response = $this->patch($client, $headers, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, ['cropMarks' => true]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertTrue($response->toArray()['cropMarks']);

        $this->em()->clear();
        $sibling = $this->em()->find(TemplateVariant::class, Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID));
        self::assertInstanceOf(TemplateVariant::class, $sibling);
        self::assertFalse($sibling->dimension->cropMarks);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    private function patch(Client $client, array $headers, string $variantId, array $body): ResponseInterface
    {
        return $client->request('PATCH', '/api/template-variants/' . $variantId . '/print-setup', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'] + $headers,
            'body' => json_encode((object) $body, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{Client, array<string, string>}
     */
    private function client(): array
    {
        $client = self::createClient();

        return [$client, ['Authorization' => 'Bearer ' . TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET)]];
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
