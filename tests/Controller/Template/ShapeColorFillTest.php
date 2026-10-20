<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;

/**
 * Recolourable shapes on the WEB fill surfaces: the `shapeColorValues[…]`
 * picks reach the renderer validated (a colour outside a list-only shape's
 * swatches is dropped leniently), are recorded in the export version and
 * seed back from it, and the fill component renders the colour mirror, the
 * preview box and the popover. Group members share the shape's id, so one
 * pick repaints every dimension.
 *
 * @covers \WBoost\Web\Controller\Template\TemplateVariantDownloadController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupFillPreviewController
 * @covers \WBoost\Web\Services\SocialNetwork\FillShapePlaceholders
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 */
final class ShapeColorFillTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string LISTED_SHAPE = '55555555-5555-4555-8555-555555555555';
    private const string FREE_SHAPE = '66666666-6666-4666-8666-666666666666';

    public function testDownloadAppliesValidPicksAndRecordsThemInTheVersion(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->addShapes(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $client->request('POST', '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download', [
            'shapeColorValues' => [
                self::LISTED_SHAPE => '#000000', // not in the list → dropped
                self::FREE_SHAPE => '#00AAFF',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $call = $this->renderer($client)->calls[0];
        self::assertSame([self::FREE_SHAPE => '#00aaff'], $call['shapeColors']);

        $version = $this->versionFor(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);
        self::assertSame(
            [self::LISTED_SHAPE => '#000000', self::FREE_SHAPE => '#00aaff'],
            $version->fillValues->toArray()['shapeColors'] ?? null,
        );

        // Seeding re-validates: the disallowed pick does not come back.
        $variant = $this->variant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);
        $seed = self::getContainer()->get(ExportVersionSeeder::class)->forVariant($version, $variant);
        self::assertSame([self::FREE_SHAPE => '#00aaff'], $seed['shapeColorValues']);
    }

    public function testFillComponentRendersMirrorBoxAndPopover(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->addShapes(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: [
                'variant' => $this->variant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID),
                'shapeColorValues' => [self::LISTED_SHAPE => '#c8102e'],
            ],
            client: $client,
        );

        $html = (string) $testComponent->render();

        self::assertStringContainsString(sprintf('name="shapeColorValues[%s]"', self::LISTED_SHAPE), $html);
        self::assertStringContainsString(sprintf('data-shape-color-mirror="%s"', self::FREE_SHAPE), $html);
        self::assertStringContainsString('fill-box fill-box--shape', $html);
        self::assertStringContainsString('fill-popover fill-popover--shape', $html);
        // Swatches: the designed colour first, then the list; the pick is marked.
        self::assertMatchesRegularExpression('/class="fill-richtext-swatch is-active"[^>]*data-color="#c8102e"/s', $html);
        self::assertStringContainsString('data-color="#112233"', $html);
        // Only the free shape gets the free picker.
        self::assertSame(1, substr_count($html, 'data-shape-picker='));
        // The pick is a render input, so it is part of the state hash.
        self::assertStringContainsString('Pruh', $html);

        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        $before = $component->fillStateHash();
        $component->shapeColorValues[self::LISTED_SHAPE] = '';
        self::assertNotSame($before, $component->fillStateHash());
    }

    public function testGroupPreviewRepaintsTheSharedShapeInEveryDimension(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->addShapes(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        $this->addShapes(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();
        // ONE field per shared shape, boxes on both dimensions.
        self::assertCount(1, $crawler->filter(sprintf('input[name="shapeColorValues[%s]"][data-action="input->group-fill#changed"]', self::LISTED_SHAPE)));
        self::assertCount(2, $crawler->filter(sprintf('.fill-box--shape[data-inputid="%s"]', self::LISTED_SHAPE)));

        foreach ([TestDataFixture::GROUPED_PRESET_VARIANT_ID, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID] as $variantId) {
            $client->request('POST', sprintf('/template-group/%s/fill-preview/%s', TestDataFixture::TEMPLATE_GROUP_1_ID, $variantId), [
                'shapeColorValues' => [self::LISTED_SHAPE => '#c8102e'],
            ]);
            self::assertResponseIsSuccessful();
            self::assertSame([self::LISTED_SHAPE => '#c8102e'], $this->renderer($client)->calls[0]['shapeColors']);
        }
    }

    /**
     * Two recolourable shapes on top of the fixture design: a list-only fill
     * ("Pruh") and a free-colour one with a border painted in one pick.
     */
    private function addShapes(string $variantId): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $this->variant($variantId);

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
        if (!isset($canvas['version'])) {
            $canvas['version'] = '7.0.0';
        }

        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        $entityManager->flush();
    }

    private function variant(string $variantId): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));
    }

    private function renderer(KernelBrowser $client): FakeTemplateVariantImageRenderer
    {
        $renderer = $client->getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function versionFor(string $variantId): TemplateExportVersion
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId]);
        self::assertCount(1, $versions);

        return $versions[0];
    }
}
