<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Imagick;
use PHPUnit\Framework\Attributes\DataProvider;
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
use WBoost\Web\Value\CanvasShadow;
use WBoost\Web\Value\CanvasShadowKind;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * "Styl vrstvy" › Vržený stín (2026-11-24) reaches the real Gotenberg export
 * at the right size: Fabric's native `shadow` with `nonScaling` px — the
 * offset doubles with the export pixel ratio (viewport), and a filled
 * placeholder casts it from the USER's picture, clipped to its frame.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class LayerShadowRenderTest extends KernelTestCase
{
    private const string PHOTO = 'aaaaaaaa-0000-4000-8000-000000000021';

    #[DataProvider('ratios')]
    public function testADropShadowFallsAtItsDesignedOffset(float $ratio): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $shadow = (new CanvasShadow(CanvasShadowKind::DropShadow, '#ff0000', 1.0, 135.0, 42.43, 0.0))->toFabric(); // +30, +30

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 400, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                ['type' => 'Rect', 'left' => 40, 'top' => 40, 'width' => 100, 'height' => 100, 'fill' => '#0000ff', 'originX' => 'left', 'originY' => 'top', 'shadow' => $shadow],
                [
                    'type' => 'Image', 'left' => 220, 'top' => 220, 'width' => 100, 'height' => 100, 'scaleX' => 1, 'scaleY' => 1,
                    'originX' => 'left', 'originY' => 'top', 'src' => self::pngDataUri('#00ff00'),
                    'inputId' => self::PHOTO, 'imagePlaceholder' => true, 'shadow' => $shadow,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];
        $variant->imageInputs = [new EditorImageInput(self::PHOTO, 'Foto', null, true, true, false, false, [])];

        // The user's picture: twice the frame (scale 0.5) — nonScaling keeps
        // the shadow at the designed px regardless of the pick's own scale.
        $userPicture = new ResolvedImageOverride(self::pngDataUri('#000000', 200), 200, 200, 1.0, 0.0, 0.0, 0.0);

        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], []),
            new ResolvedImageOverrides([self::PHOTO => $userPicture], []),
            pixelRatio: $ratio,
        );

        $image = new Imagick();
        $image->readImageBlob($png);
        $at = static fn (int $x, int $y): string => self::hexAt($image, (int) round($x * $ratio), (int) round($y * $ratio));

        self::assertSame('#0000ff', $at(90, 90), 'the shape itself');
        self::assertSame('#ff0000', $at(160, 160), 'its shadow 30 px right and down');
        self::assertSame('#ffffff', $at(30, 160), 'nothing left of it');
        self::assertSame('#000000', $at(270, 270), 'the user\'s picture in the slot');
        self::assertSame('#ff0000', $at(340, 340), 'the slot casts the shadow from its frame');
        self::assertSame('#ffffff', $at(360, 360), 'and no further than its frame + the offset');
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function ratios(): iterable
    {
        yield '1x' => [1.0];
        yield '2x' => [2.0];
    }

    private static function hexAt(Imagick $image, int $x, int $y): string
    {
        $color = $image->getImagePixelColor($x, $y)->getColor();

        return sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
    }

    private static function pngDataUri(string $color, int $size = 100): string
    {
        $picture = new Imagick();
        $picture->newImage($size, $size, $color);
        $picture->setImageFormat('png');

        return 'data:image/png;base64,' . base64_encode($picture->getImageBlob());
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
