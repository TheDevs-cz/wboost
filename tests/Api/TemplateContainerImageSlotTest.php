<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * Container-rows plan P2a over the API: a fillable image slot that flows in a
 * container is listed in that container's `memberImageInputIds` (never in
 * `memberInputIds`, which stays text-only), counts for the container's `y`
 * anchor, and its `imageInputs[]` entry carries the `containerId`. A slot
 * outside every container keeps `containerId: null`, and a container without
 * slots gets an empty `memberImageInputIds` (backward compatible).
 *
 * @covers \WBoost\Web\Api\Templates\TemplatesProvider
 * @covers \WBoost\Web\Value\ResolvedCanvasContainer
 */
final class TemplateContainerImageSlotTest extends ApiTestCase
{
    public function testTheListingExposesSlotMembersAndTheirContainer(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET);

        $before = $this->variantRow($client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray());
        self::assertSame([], $before['containers'][0]['memberImageInputIds'] ?? null, 'no slot members yet');

        $this->addPhotoToTheContainer();

        $row = $this->variantRow($client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray());

        $container = $row['containers'][0];
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID, $container['id']);
        self::assertSame(
            [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID],
            $container['memberInputIds'],
            'memberInputIds stays text-only',
        );
        self::assertSame([TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID], $container['memberImageInputIds']);
        // Headline at y 60 is still the highest member (the photo starts at 120).
        self::assertEquals(60, $container['y']);

        $slots = [];
        foreach ($row['imageInputs'] as $slot) {
            self::assertIsString($slot['id']);
            $slots[$slot['id']] = $slot;
        }
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID, $slots[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID]['containerId']);
        self::assertNull($slots[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID]['containerId']);
        // `frame` stays the DESIGNED box.
        self::assertEquals(['x' => 100, 'y' => 120, 'width' => 400, 'height' => 300], $slots[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID]['frame']);
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array{containers: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private function variantRow(array $body): array
    {
        foreach ($body as $template) {
            self::assertIsArray($template);
            self::assertIsArray($template['variants']);
            foreach ($template['variants'] as $row) {
                self::assertIsArray($row);
                if ($row['id'] === TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID) {
                    /** @var array{containers: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $row */
                    return $row;
                }
            }
        }

        self::fail('variant not listed');
    }

    private function addPhotoToTheContainer(): void
    {
        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $canvas = json_decode($variant->canvas, true);
        self::assertIsArray($canvas);
        self::assertIsArray($canvas['containers']);
        self::assertIsArray($canvas['containers'][0]);
        self::assertIsArray($canvas['containers'][0]['memberInputIds']);
        $canvas['containers'][0]['memberInputIds'][] = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID;

        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
