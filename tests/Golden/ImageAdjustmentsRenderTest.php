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
use WBoost\Web\Value\ImageAdjustments;
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * "Styl vrstvy" › Úpravy obrázku (2026-11-24) in the real Gotenberg export:
 * Fabric's native `filters`, run by the render template on the Canvas2D
 * backend, on a decorative image AND on the user's picture in a filled
 * placeholder (the fill swaps the src, the filters stay). A big photo with a
 * blur must stay well inside the render cap.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ImageAdjustmentsRenderTest extends KernelTestCase
{
    private const string PHOTO = 'aaaaaaaa-0000-4000-8000-000000000041';

    public function testFiltersRunOnDecorativeImagesAndOnTheUsersPicture(): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 400, 'fill' => '#0000ff', 'originX' => 'left', 'originY' => 'top'],
                // Red, made černobílá → grey.
                ['type' => 'Image', 'left' => 20, 'top' => 20, 'width' => 100, 'height' => 100, 'scaleX' => 1, 'scaleY' => 1,
                    'originX' => 'left', 'originY' => 'top', 'src' => self::pngDataUri('#ff0000'),
                    'filters' => (new ImageAdjustments(grayscale: true))->toFabric()],
                // A slot at Jas −50: the user's WHITE picture renders mid-grey.
                ['type' => 'Image', 'left' => 220, 'top' => 220, 'width' => 100, 'height' => 100, 'scaleX' => 1, 'scaleY' => 1,
                    'originX' => 'left', 'originY' => 'top', 'src' => self::pngDataUri('#00ff00'),
                    'inputId' => self::PHOTO, 'imagePlaceholder' => true,
                    'filters' => (new ImageAdjustments(brightness: -50))->toFabric()],
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];
        $variant->imageInputs = [new EditorImageInput(self::PHOTO, 'Foto', null, true, true, false, false, [])];

        $userPicture = new ResolvedImageOverride(self::pngDataUri('#ffffff'), 100, 100, 1.0, 0.0, 0.0, 0.0);

        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], []),
            new ResolvedImageOverrides([self::PHOTO => $userPicture], []),
        );

        $image = new Imagick();
        $image->readImageBlob($png);

        $grey = self::hexAt($image, 70, 70);
        self::assertMatchesRegularExpression('/^#([0-9a-f]{2})\1\1$/', $grey, sprintf('the red picture renders grey (got %s)', $grey));
        self::assertNotSame('#ff0000', $grey);

        $dimmed = self::hexAt($image, 270, 270);
        self::assertMatchesRegularExpression('/^#(7[0-9a-f]|8[0-9a-f])\1\1$/', $dimmed, sprintf('the user\'s white picture at Jas −50 (got %s)', $dimmed));
    }

    public function testABlurredBigPhotoRendersWellInsideTheCap(): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $photo = new Imagick();
        $photo->newPseudoImage(4000, 3000, 'plasma:fractal');
        $photo->setImageFormat('jpeg');
        $src = 'data:image/jpeg;base64,' . base64_encode($photo->getImageBlob());

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [[
                'type' => 'Image', 'left' => 0, 'top' => 0, 'width' => 4000, 'height' => 3000, 'scaleX' => 0.1, 'scaleY' => 0.1,
                'originX' => 'left', 'originY' => 'top', 'src' => $src,
                'filters' => (new ImageAdjustments(brightness: -20, contrast: 10, saturation: -30, blur: 30))->toFabric(),
            ]],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];
        $variant->imageInputs = [];

        $started = microtime(true);
        $png = $this->realRenderer()->renderToBytes($variant, new ResolvedInputOverrides([], []));
        $seconds = microtime(true) - $started;

        self::assertNotSame('', $png);
        fwrite(STDERR, sprintf("\n[adjust] 4000x3000 blurred photo rendered in %.2f s\n", $seconds));
        self::assertLessThan(15.0, $seconds, 'a 12 MP photo with four adjustments incl. blur must render well inside the 20 s cap');
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
