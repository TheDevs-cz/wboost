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
 * "Styl vrstvy" › Režim prolnutí (2026-11-24) reaches the real Gotenberg
 * export: Fabric's native `globalCompositeOperation` blends a shape and the
 * USER's picture in a filled placeholder with what lies under them. Red
 * multiplied over blue is black, a white picture multiplied over blue stays
 * blue — while the normal twins over the same blue paint their own colour.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class LayerBlendRenderTest extends KernelTestCase
{
    private const string PHOTO_BLEND = 'aaaaaaaa-0000-4000-8000-000000000011';
    private const string PHOTO_NORMAL = 'aaaaaaaa-0000-4000-8000-000000000012';

    public function testShapesAndFilledPlaceholdersBlendWithWhatIsBelow(): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $rect = static fn (float $left, float $top, string $fill, string $blend): array => [
            'type' => 'Rect', 'left' => $left, 'top' => $top, 'width' => 100, 'height' => 100,
            'originX' => 'left', 'originY' => 'top', 'fill' => $fill, 'globalCompositeOperation' => $blend,
        ];
        $placeholder = static fn (string $inputId, float $left, string $blend): array => [
            'type' => 'Image', 'left' => $left, 'top' => 220, 'width' => 100, 'height' => 100, 'scaleX' => 1, 'scaleY' => 1,
            'originX' => 'left', 'originY' => 'top', 'src' => self::pngDataUri('#ff0000'),
            'inputId' => $inputId, 'imagePlaceholder' => true, 'globalCompositeOperation' => $blend,
        ];

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 400, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                // A blue band under everything that blends.
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 340, 'fill' => '#0000ff', 'originX' => 'left', 'originY' => 'top'],
                $rect(10, 10, '#ff0000', 'multiply'),
                $rect(210, 10, '#ff0000', 'source-over'),
                $placeholder(self::PHOTO_BLEND, 10, 'multiply'),
                $placeholder(self::PHOTO_NORMAL, 210, 'source-over'),
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];
        $variant->imageInputs = [
            new EditorImageInput(self::PHOTO_BLEND, 'Blend', null, true, true, false, false, []),
            new EditorImageInput(self::PHOTO_NORMAL, 'Normal', null, true, true, false, false, []),
        ];

        // The user's picture: solid white, replacing the red stand-in.
        $userPicture = new ResolvedImageOverride(self::pngDataUri('#ffffff'), 100, 100, 1.0, 0.0, 0.0, 0.0);

        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], []),
            new ResolvedImageOverrides([self::PHOTO_BLEND => $userPicture, self::PHOTO_NORMAL => $userPicture], []),
        );

        $image = new Imagick();
        $image->readImageBlob($png);

        self::assertSame('#000000', self::hexAt($image, 60, 60), 'red multiplied over blue is black');
        self::assertSame('#ff0000', self::hexAt($image, 260, 60), 'the normal red square paints red');
        self::assertSame('#0000ff', self::hexAt($image, 60, 270), 'the user\'s white picture multiplied over blue stays blue');
        self::assertSame('#ffffff', self::hexAt($image, 260, 270), 'the normal slot shows the user\'s white picture');
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
