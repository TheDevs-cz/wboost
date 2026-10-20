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
use WBoost\Web\Services\SocialNetwork\ResolveShapeColorOverrides;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * End-to-end proof that a recolourable shape's pick reaches the real Gotenberg
 * pixels: the opened-up rectangle renders in the picked colour (fill AND
 * border for a `both` shape), the decorative neighbour stays as designed.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ShapeColorRenderTest extends KernelTestCase
{
    private const string SHAPE_ID = '77777777-7777-4777-8777-777777777777';

    public function testPickedColourRepaintsTheShapeInTheExport(): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 400, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                [
                    'type' => 'Rect', 'left' => 40, 'top' => 40, 'width' => 200, 'height' => 200, 'originX' => 'left', 'originY' => 'top',
                    'fill' => '#112233', 'stroke' => '#000000', 'strokeWidth' => 20, 'strokeUniform' => true,
                    'inputId' => self::SHAPE_ID, 'colorChoice' => 'both', 'allowedColors' => ['#e74c3c'],
                ],
                [
                    'type' => 'Rect', 'left' => 300, 'top' => 300, 'width' => 80, 'height' => 80, 'originX' => 'left', 'originY' => 'top',
                    'fill' => '#112233', 'inputId' => '88888888-8888-4888-8888-888888888888',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];

        $colors = (new ResolveShapeColorOverrides())->forVariant($variant, [self::SHAPE_ID => '#E74C3C'], lenient: false);
        self::assertSame([self::SHAPE_ID => '#e74c3c'], $colors);

        $png = $this->realRenderer()->renderToBytes(
            $variant,
            (new ResolvedInputOverrides([], []))->withShapeColors($colors),
            ResolvedImageOverrides::none(),
        );

        $image = new Imagick();
        $image->readImageBlob($png);

        self::assertSame('#e74c3c', self::hexAt($image, 140, 140), 'the fill is repainted');
        self::assertSame('#e74c3c', self::hexAt($image, 40, 140), 'the border is repainted too (both)');
        self::assertSame('#112233', self::hexAt($image, 340, 340), 'the decorative shape keeps its colour');
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
