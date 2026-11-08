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
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Admin-set opacity (2026-11-08) reaches the real Gotenberg export on every
 * kind it is offered for: a text filled by the user, and an image
 * placeholder filled with the USER's picture — both keep the designed 50 %
 * over white and read mid-grey, while their opaque twins stay black. The
 * render template paints Fabric's native `opacity`; the fill swaps the
 * text / the picture and its placement, never the opacity.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ObjectOpacityRenderTest extends KernelTestCase
{
    private const string TEXT_HALF = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string TEXT_FULL = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const string PHOTO_HALF = 'aaaaaaaa-0000-4000-8000-000000000003';
    private const string PHOTO_FULL = 'aaaaaaaa-0000-4000-8000-000000000004';

    public function testTranslucentTextAndFilledPlaceholderKeepTheirDesignedOpacity(): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $textbox = static fn (string $inputId, float $left, float $opacity): array => [
            'type' => 'Textbox', 'left' => $left, 'top' => 10, 'width' => 180, 'originX' => 'left', 'originY' => 'top',
            'fontSize' => 160, 'fontFamily' => 'sans-serif', 'fontWeight' => 'bold', 'lineHeight' => 1, 'fill' => '#000000',
            'text' => 'X', 'inputId' => $inputId, 'opacity' => $opacity,
        ];
        $placeholder = static fn (string $inputId, float $left, float $opacity): array => [
            'type' => 'Image', 'left' => $left, 'top' => 220, 'width' => 100, 'height' => 100, 'scaleX' => 1.5, 'scaleY' => 1.5,
            'originX' => 'left', 'originY' => 'top', 'src' => self::pngDataUri('#ff0000'),
            'inputId' => $inputId, 'imagePlaceholder' => true, 'opacity' => $opacity,
        ];

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 400, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                $textbox(self::TEXT_HALF, 10, 0.5),
                $textbox(self::TEXT_FULL, 210, 1),
                $placeholder(self::PHOTO_HALF, 20, 0.5),
                $placeholder(self::PHOTO_FULL, 220, 1),
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [
            new EditorTextInput(self::TEXT_HALF, 'Half', null, false, false, null, false),
            new EditorTextInput(self::TEXT_FULL, 'Full', null, false, false, null, false),
        ];
        $variant->imageInputs = [
            new EditorImageInput(self::PHOTO_HALF, 'Half', null, true, true, false, false, []),
            new EditorImageInput(self::PHOTO_FULL, 'Full', null, true, true, false, false, []),
        ];

        // The user's picture: solid black, replacing the red stand-in.
        $userPicture = new ResolvedImageOverride(self::pngDataUri('#000000'), 100, 100, 1.0, 0.0, 0.0, 0.0);

        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([self::TEXT_HALF => 'I', self::TEXT_FULL => 'I'], []),
            new ResolvedImageOverrides([self::PHOTO_HALF => $userPicture, self::PHOTO_FULL => $userPicture], []),
        );

        $image = new Imagick();
        $image->readImageBlob($png);

        self::assertLessThan(0x20, self::darkest($image, 210, 10, 180, 180), 'the opaque text renders black');
        self::assertEqualsWithDelta(0x80, self::darkest($image, 10, 10, 180, 180), 0x14, 'the 50 % text renders mid-grey over white');

        self::assertSame('#000000', self::hexAt($image, 295, 295), 'the opaque slot shows the user\'s black picture');
        $half = self::hexAt($image, 95, 295);
        self::assertMatchesRegularExpression('/^#(7[0-9a-f]|8[0-9a-f])\1\1$/', $half, sprintf('the 50 %% slot shows the user\'s picture at 50 %% (got %s; red would mean the stand-in leaked)', $half));
    }

    /** The darkest red-channel value inside a box (the glyph's ink). */
    private static function darkest(Imagick $image, int $x, int $y, int $width, int $height): int
    {
        /** @var list<int> $pixels */
        $pixels = $image->exportImagePixels($x, $y, $width, $height, 'R', Imagick::PIXEL_CHAR);

        return $pixels === [] ? 255 : min($pixels);
    }

    private static function hexAt(Imagick $image, int $x, int $y): string
    {
        $color = $image->getImagePixelColor($x, $y)->getColor();

        return sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
    }

    private static function pngDataUri(string $color): string
    {
        $picture = new Imagick();
        $picture->newImage(100, 100, $color);
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
