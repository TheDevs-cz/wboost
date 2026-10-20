<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * Recolourable shapes over the API: the listing describes them
 * (`variants[].shapeInputs[]`), every render endpoint accepts `shapeColors`
 * STRICTLY (structured 400s tagged `shapeInputId`), and the recorded version
 * hands the picks back in `fill.shapeColors`.
 *
 * @covers \WBoost\Web\Api\Templates\TemplatesProvider
 * @covers \WBoost\Web\Services\Template\ApiExportRenderer
 * @covers \WBoost\Web\Services\Template\ExportVersionApiView
 */
final class TemplateShapeColorsTest extends ApiTestCase
{
    private const string LISTED_SHAPE = '55555555-5555-4555-8555-555555555555';
    private const string FREE_SHAPE = '66666666-6666-4666-8666-666666666666';

    public function testListingDescribesRecolourableShapes(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addShapes(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $body = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        $variant = null;
        foreach ($body as $template) {
            self::assertIsArray($template);
            self::assertIsArray($template['variants']);
            foreach ($template['variants'] as $row) {
                self::assertIsArray($row);
                if ($row['id'] === TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID) {
                    $variant = $row;
                }
            }
        }

        self::assertIsArray($variant);
        self::assertIsArray($variant['shapeInputs']);
        self::assertCount(2, $variant['shapeInputs']);
        [$listed, $free] = $variant['shapeInputs'];
        self::assertIsArray($listed);
        self::assertIsArray($free);

        self::assertSame(self::LISTED_SHAPE, $listed['id']);
        self::assertSame('Pruh', $listed['name']);
        self::assertSame('fill', $listed['target']);
        self::assertSame('#112233', $listed['defaultColor']);
        self::assertSame(['#112233', '#c8102e', '#ffffff'], $listed['colorOptions']);
        self::assertFalse($listed['freeColor']);
        self::assertEquals(['x' => 10, 'y' => 20, 'width' => 100, 'height' => 40], $listed['frame']);
        self::assertIsInt($listed['layerIndex']);

        self::assertSame(self::FREE_SHAPE, $free['id']);
        self::assertSame('both', $free['target']);
        self::assertTrue($free['freeColor']);
    }

    public function testExportPaintsValidPicksAndRecordsThemInTheVersion(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addShapes(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $this->export($client, $token, [self::LISTED_SHAPE => '#C8102E', self::FREE_SHAPE => '#0af']);
        $this->assertResponseIsSuccessful();

        $calls = $this->renderer($client)->calls;
        self::assertSame(
            [self::LISTED_SHAPE => '#c8102e', self::FREE_SHAPE => '#00aaff'],
            $calls[count($calls) - 1]['shapeColors'],
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $version = $entityManager->getRepository(TemplateExportVersion::class)
            ->findOneBy(['variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);

        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertIsArray($detail['fill']);
        self::assertSame(
            [self::LISTED_SHAPE => '#c8102e', self::FREE_SHAPE => '#00aaff'],
            $detail['fill']['shapeColors'],
        );
    }

    public function testColourOutsideTheListIs400WithTheOptions(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addShapes(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $response = $this->export($client, $token, [self::LISTED_SHAPE => '#000000']);

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('shape_color_not_allowed', $body['code'] ?? null);
        self::assertSame(self::LISTED_SHAPE, $body['shapeInputId'] ?? null);
        self::assertSame(['#112233', '#c8102e', '#ffffff'], $body['allowedColors'] ?? null);
    }

    public function testNonHexColourIs400(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addShapes(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $response = $this->export($client, $token, [self::FREE_SHAPE => 'red']);

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('invalid_shape_color', $body['code'] ?? null);
        self::assertSame(self::FREE_SHAPE, $body['shapeInputId'] ?? null);
    }

    public function testGroupPreviewPassesTheSharedPick(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addShapes(TestDataFixture::GROUPED_PRESET_VARIANT_ID);

        $client->request(
            'POST',
            sprintf('/api/template-groups/%s/preview/%s', TestDataFixture::TEMPLATE_GROUP_1_ID, TestDataFixture::GROUPED_PRESET_VARIANT_ID),
            [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'body' => json_encode(['shapeColors' => [self::LISTED_SHAPE => '#ffffff']], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $calls = $this->renderer($client)->calls;
        self::assertSame([self::LISTED_SHAPE => '#ffffff'], $calls[count($calls) - 1]['shapeColors']);
    }

    /**
     * @param array<string, string> $shapeColors
     */
    private function export(Client $client, string $token, array $shapeColors): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $client->request(
            'POST',
            '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'body' => json_encode(['shapeColors' => $shapeColors], JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function token(Client $client): string
    {
        return TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );
    }

    private function renderer(Client $client): FakeTemplateVariantImageRenderer
    {
        $renderer = $client->getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function addShapes(string $variantId): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));

        $canvas = json_decode($variant->canvas, true);
        $canvas = is_array($canvas) ? $canvas : [];
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $objects[] = [
            'type' => 'Rect', 'left' => 10, 'top' => 20, 'width' => 100, 'height' => 40,
            'originX' => 'left', 'originY' => 'top', 'fill' => '#112233',
            'inputId' => self::LISTED_SHAPE, 'name' => 'Pruh', 'shapeKind' => 'rectangle',
            'colorChoice' => 'fill', 'allowedColors' => ['#c8102e', '#ffffff'],
        ];
        $objects[] = [
            'type' => 'Circle', 'left' => 200, 'top' => 200, 'radius' => 30, 'width' => 60, 'height' => 60,
            'originX' => 'left', 'originY' => 'top', 'fill' => '#445566', 'stroke' => '#000000', 'strokeWidth' => 3,
            'inputId' => self::FREE_SHAPE, 'shapeKind' => 'circle',
            'colorChoice' => 'both', 'allowedColors' => [], 'colorFree' => true,
        ];
        $canvas['objects'] = $objects;
        $canvas['version'] ??= '7.0.0';

        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        $entityManager->flush();
    }
}
