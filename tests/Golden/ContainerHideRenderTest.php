<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Imagick;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * End-to-end proof (real Gotenberg pixels) of the whole-container hide: a
 * hidden container takes its WHOLE tree with it (own members + a nested
 * container's), and a hidden nested container COLLAPSES in its parent's flow
 * exactly like a hidden member — the item below moves up into its place.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 * @covers \WBoost\Web\Value\ResolvedInputOverrides
 */
#[Group('gotenberg')]
final class ContainerHideRenderTest extends KernelTestCase
{
    public function testAHiddenContainerTakesItsWholeTreeAndCollapsesInItsParent(): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $rect = static fn (string $id, int $left, int $top, string $fill): array => [
            'type' => 'Rect', 'left' => $left, 'top' => $top, 'width' => 80, 'height' => 40,
            'originX' => 'left', 'originY' => 'top', 'fill' => $fill, 'inputId' => $id, 'shapeKind' => 'rectangle',
        ];

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 1080, 'height' => 1080, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                // Left column: a hidable "bubble" (A) nesting a child (B, C).
                $rect('a', 20, 20, '#ff0000'),
                $rect('b', 20, 80, '#00ff00'),
                $rect('c', 20, 140, '#0000ff'),
                // Right column: a flow whose first item is a hidable nested
                // container (M1, M2) followed by N.
                $rect('m1', 300, 20, '#ff00ff'),
                $rect('m2', 300, 70, '#ff00ff'),
                $rect('n', 300, 130, '#000000'),
            ],
            'containers' => [
                ['id' => 'bubble', 'maxHeight' => 600, 'memberInputIds' => ['a'], 'memberContainerIds' => ['bubble-child'], 'hidable' => true],
                ['id' => 'bubble-child', 'maxHeight' => 600, 'memberInputIds' => ['b', 'c']],
                ['id' => 'flow', 'maxHeight' => 600, 'memberInputIds' => ['n'], 'memberContainerIds' => ['mid']],
                ['id' => 'mid', 'maxHeight' => 600, 'memberInputIds' => ['m1', 'm2'], 'hidable' => true],
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];

        $shown = $this->render($variant, []);
        self::assertSame('#ff0000', self::hexAt($shown, 60, 40));
        self::assertSame('#0000ff', self::hexAt($shown, 60, 160));
        self::assertSame('#ff00ff', self::hexAt($shown, 340, 40));
        self::assertSame('#000000', self::hexAt($shown, 340, 150), 'N at its designed place');

        $hidden = $this->render($variant, ['bubble', 'mid']);
        self::assertSame('#ffffff', self::hexAt($hidden, 60, 40), 'own member gone');
        self::assertSame('#ffffff', self::hexAt($hidden, 60, 100), 'nested member gone');
        self::assertSame('#ffffff', self::hexAt($hidden, 60, 160), 'nested member gone');
        self::assertSame('#000000', self::hexAt($hidden, 340, 40), 'N collapsed up into the hidden child\'s place');
        self::assertSame('#ffffff', self::hexAt($hidden, 340, 150));
    }

    /**
     * @param list<string> $hiddenContainerIds
     */
    private function render(\WBoost\Web\Entity\TemplateVariant $variant, array $hiddenContainerIds): Imagick
    {
        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], [], hiddenContainerIds: $hiddenContainerIds),
            ResolvedImageOverrides::none(),
        );

        $image = new Imagick();
        $image->readImageBlob($png);

        return $image;
    }

    private static function hexAt(Imagick $image, int $x, int $y): string
    {
        $color = $image->getImagePixelColor($x, $y)->getColor();

        return sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
    }

    private function realRenderer(): TemplateVariantImageRenderer
    {
        $container = self::getContainer();
        $geometry = new CanvasPlaceholderGeometry();
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        return new TemplateVariantImageRenderer(
            $container->get(GotenbergScreenshotInterface::class),
            $container->get(GetFonts::class),
            $container->get(AssetInliner::class),
            $geometry,
            new TextInputObjectBinder($geometry),
            new ImagePlacement(),
            $container->get(UploaderHelper::class),
            $projectDir . '/assets/fabric/fabric-7.3.1.min.js',
            $projectDir . '/assets/editor/fabric_break_word.js',
            $projectDir . '/assets/editor/container_layout.js',
            $projectDir . '/assets/editor/rich_text_runs.js',
            $projectDir . '/assets/editor/rich_text_blocks.js',
            new TagAwareAdapter(new ArrayAdapter()),
        );
    }
}
