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
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * Whole-container hide over the API: the listing exposes `hidable` + `name`
 * on `variants[].containers[]`, `inputs[<containerId>] = {hide: true}` hides
 * the container's whole tree (texts, decorative images, nested containers)
 * on export/preview, a non-hidable or malformed container entry is a
 * structured 400, and the recorded version hands the hide back.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\ValidateContainerHides
 * @covers \WBoost\Web\Services\Template\ApiExportRenderer
 * @covers \WBoost\Web\Api\Templates\TemplatesProvider
 */
final class TemplateContainerHideTest extends ApiTestCase
{
    private const string BUBBLE = 'aaaaaaaa-0000-4000-8000-0000000000b1';
    private const string BUBBLE_CHILD = 'aaaaaaaa-0000-4000-8000-0000000000b2';
    private const string FIXED = 'aaaaaaaa-0000-4000-8000-0000000000b3';
    private const string BUBBLE_IMAGE = 'aaaaaaaa-0000-4000-8000-0000000000d1';
    private const string CHILD_SHAPE = 'aaaaaaaa-0000-4000-8000-0000000000d2';
    private const string DECOR_ONLY = 'aaaaaaaa-0000-4000-8000-0000000000b4';
    private const string DECOR_A = 'aaaaaaaa-0000-4000-8000-0000000000d3';
    private const string DECOR_B = 'aaaaaaaa-0000-4000-8000-0000000000d4';

    public function testListingExposesHidableAndName(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $body = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        $containers = [];
        foreach ($body as $template) {
            self::assertIsArray($template);
            self::assertIsArray($template['variants']);
            foreach ($template['variants'] as $row) {
                self::assertIsArray($row);
                if ($row['id'] === TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID) {
                    self::assertIsArray($row['containers']);
                    foreach ($row['containers'] as $container) {
                        self::assertIsArray($container);
                        self::assertIsString($container['id']);
                        $containers[$container['id']] = $container;
                    }
                }
            }
        }

        self::assertTrue($containers[self::BUBBLE]['hidable'] ?? null);
        self::assertSame('Bublina', $containers[self::BUBBLE]['name'] ?? null);
        self::assertFalse($containers[self::FIXED]['hidable'] ?? null);
        self::assertNull($containers[self::FIXED]['name'] ?? null);

        // A hidable container holding ONLY decorative images / shapes is
        // listed too (its toggle must be offerable), anchored at its highest
        // decorative member, with no fillable members.
        self::assertArrayHasKey(self::DECOR_ONLY, $containers);
        self::assertTrue($containers[self::DECOR_ONLY]['hidable'] ?? null);
        self::assertSame('Ozdoby', $containers[self::DECOR_ONLY]['name'] ?? null);
        self::assertSame([], $containers[self::DECOR_ONLY]['memberInputIds'] ?? null);
        self::assertEquals(700, $containers[self::DECOR_ONLY]['y'] ?? null);
    }

    public function testExportHidesADecorationOnlyContainer(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $this->post($client, $token, 'export', [self::DECOR_ONLY => ['hide' => true]]);
        $this->assertResponseIsSuccessful();
        $hidden = $this->lastCall($client)['effectiveHidden'];
        self::assertTrue($hidden[self::DECOR_A] ?? false);
        self::assertTrue($hidden[self::DECOR_B] ?? false);
    }

    public function testExportHidesTheWholeTreeAndRecordsTheHide(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $this->post($client, $token, 'export', [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => ['value' => 'Ahoj'],
            self::BUBBLE => ['hide' => true],
        ]);
        $this->assertResponseIsSuccessful();

        $call = $this->lastCall($client);
        self::assertSame([self::BUBBLE], $call['hiddenContainerIds']);
        $hidden = array_keys(array_filter($call['effectiveHidden']));
        sort($hidden);
        $expected = [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID,
            self::BUBBLE_IMAGE,
            self::CHILD_SHAPE,
        ];
        sort($expected);
        self::assertSame($expected, $hidden);
        // The members' own state is untouched (the headline's text still rides).
        self::assertSame('Ahoj', $call['texts'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID] ?? null);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $version = $entityManager->getRepository(TemplateExportVersion::class)
            ->findOneBy(['variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        self::assertContains(self::BUBBLE, $version->fillValues->hidden);

        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertIsArray($detail['fill']);
        self::assertIsArray($detail['fill']['inputs']);
        self::assertSame(['hide' => true], $detail['fill']['inputs'][self::BUBBLE] ?? null);
    }

    public function testPreviewHonoursTheHideToo(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $this->post($client, $token, 'preview', [self::BUBBLE => ['hide' => true]]);
        $this->assertResponseIsSuccessful();
        self::assertTrue($this->lastCall($client)['effectiveHidden'][self::BUBBLE_IMAGE] ?? false);
    }

    public function testHidingANonHidableContainerIs400(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $response = $this->post($client, $token, 'export', [self::FIXED => ['hide' => true]]);

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('container_not_hidable', $body['code'] ?? null);
        self::assertSame(self::FIXED, $body['containerId'] ?? null);
    }

    public function testAContainerEntryWithATextIs400(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $response = $this->post($client, $token, 'export', [self::BUBBLE => ['value' => 'x']]);

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('invalid_value', $body['code'] ?? null);
        self::assertSame(self::BUBBLE, $body['containerId'] ?? null);
    }

    public function testUnknownIdsKeepBeingIgnored(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $this->post($client, $token, 'export', ['not-a-container' => ['hide' => true]]);

        $this->assertResponseIsSuccessful();
        self::assertSame([], array_filter($this->lastCall($client)['effectiveHidden']));
    }

    public function testGroupPreviewHidesTheSharedContainerAndRefusesANonHidableOne(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->addContainers(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        $url = sprintf('/api/template-groups/%s/preview/%s', TestDataFixture::TEMPLATE_GROUP_1_ID, TestDataFixture::GROUPED_PRESET_VARIANT_ID);

        $client->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['inputs' => [self::BUBBLE => ['hide' => true]]], JSON_THROW_ON_ERROR),
        ]);
        $this->assertResponseIsSuccessful();
        self::assertTrue($this->lastCall($client)['effectiveHidden'][self::CHILD_SHAPE] ?? false);

        $response = $client->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['inputs' => [self::FIXED => ['hide' => true]]], JSON_THROW_ON_ERROR),
        ]);
        $this->assertResponseStatusCodeSame(400);
        self::assertSame('container_not_hidable', $response->toArray(false)['code'] ?? null);
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function post(Client $client, string $token, string $endpoint, array $inputs): ResponseInterface
    {
        return $client->request(
            'POST',
            '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/' . $endpoint,
            [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'body' => json_encode(['inputs' => $inputs], JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @return array{hiddenContainerIds: list<string>, effectiveHidden: array<string, bool>, texts: array<string, string>}
     */
    private function lastCall(Client $client): array
    {
        $renderer = $this->renderer($client);
        self::assertNotEmpty($renderer->calls);
        $last = count($renderer->calls) - 1;
        $hide = $renderer->hideCalls[$last];

        return [
            'hiddenContainerIds' => $hide['hiddenContainerIds'],
            'effectiveHidden' => $hide['effectiveHidden'],
            'texts' => $renderer->calls[$last]['texts'],
        ];
    }

    private function renderer(Client $client): FakeTemplateVariantImageRenderer
    {
        $renderer = $client->getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function token(Client $client): string
    {
        return TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );
    }

    /**
     * Headline + tagline + a decorative "bubble" image in a hidable root that
     * nests a child holding a shape; badge + an image in a NON-hidable one.
     */
    private function addContainers(string $variantId): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));

        $canvas = json_decode($variant->canvas, true);
        $canvas = is_array($canvas) ? $canvas : [];
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $objects[] = [
            'type' => 'Image', 'inputId' => self::BUBBLE_IMAGE,
            'left' => 60, 'top' => 50, 'width' => 560, 'height' => 120,
            'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
        ];
        $objects[] = [
            'type' => 'Rect', 'inputId' => self::CHILD_SHAPE, 'shapeKind' => 'rectangle',
            'left' => 80, 'top' => 280, 'width' => 300, 'height' => 4,
            'originX' => 'left', 'originY' => 'top', 'fill' => '#112233',
        ];
        $objects[] = [
            'type' => 'Rect', 'inputId' => self::DECOR_A, 'shapeKind' => 'rectangle',
            'left' => 700, 'top' => 700, 'width' => 100, 'height' => 20,
            'originX' => 'left', 'originY' => 'top', 'fill' => '#445566',
        ];
        $objects[] = [
            'type' => 'Image', 'inputId' => self::DECOR_B,
            'left' => 700, 'top' => 760, 'width' => 100, 'height' => 40,
            'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
        ];
        $canvas['objects'] = $objects;
        $canvas['containers'] = [
            ['id' => self::DECOR_ONLY, 'maxHeight' => 200, 'name' => 'Ozdoby', 'hidable' => true, 'memberInputIds' => [self::DECOR_A, self::DECOR_B]],
            [
                'id' => self::BUBBLE, 'maxHeight' => 400, 'name' => 'Bublina', 'hidable' => true,
                'memberInputIds' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, self::BUBBLE_IMAGE],
                'memberContainerIds' => [self::BUBBLE_CHILD],
            ],
            [
                'id' => self::BUBBLE_CHILD, 'maxHeight' => 100,
                'memberInputIds' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID, self::CHILD_SHAPE],
            ],
            [
                'id' => self::FIXED, 'maxHeight' => 300,
                'memberInputIds' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_LOCKED_ID],
            ],
        ];

        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        $entityManager->flush();
    }
}
