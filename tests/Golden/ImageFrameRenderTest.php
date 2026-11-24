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
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * "Styl vrstvy" › an image's Zaoblení rohů + Tah (2026-11-24) in the real
 * Gotenberg export, drawn by assets/editor/fabric_image_frame.js: rounded
 * corners and an INSIDE border along the visible frame — the image's own
 * box, and for a filled placeholder the SLOT's frame, not the bigger
 * picture panned inside it.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class ImageFrameRenderTest extends KernelTestCase
{
    private const string PHOTO = 'aaaaaaaa-0000-4000-8000-000000000031';

    #[DataProvider('ratios')]
    public function testRoundedCornersAndAnInsideBorderFollowTheFrame(float $ratio): void
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $frame = ['frameRadius' => 30, 'frameBorder' => ['color' => '#ffffff', 'width' => 10]];

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 400, 'fill' => '#0000ff', 'originX' => 'left', 'originY' => 'top'],
                // Decorative: a 50 px picture shown at 100 px (scale 2) — the
                // px must not double with the object's scale.
                ['type' => 'Image', 'left' => 40, 'top' => 40, 'width' => 50, 'height' => 50, 'scaleX' => 2, 'scaleY' => 2,
                    'originX' => 'left', 'originY' => 'top', 'src' => self::pngDataUri('#ff0000', 50)] + $frame,
                ['type' => 'Image', 'left' => 220, 'top' => 220, 'width' => 100, 'height' => 100, 'scaleX' => 1, 'scaleY' => 1,
                    'originX' => 'left', 'originY' => 'top', 'src' => self::pngDataUri('#ff0000'),
                    'inputId' => self::PHOTO, 'imagePlaceholder' => true] + $frame,
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];
        $variant->imageInputs = [new EditorImageInput(self::PHOTO, 'Foto', null, true, true, false, false, [])];

        // The user's picture: 300 px green, covering the 100 px frame.
        $userPicture = new ResolvedImageOverride(self::pngDataUri('#00ff00', 300), 300, 300, 1.0, 0.0, 0.0, 0.0);

        $png = $this->realRenderer()->renderToBytes(
            $variant,
            new ResolvedInputOverrides([], []),
            new ResolvedImageOverrides([self::PHOTO => $userPicture], []),
            pixelRatio: $ratio,
        );

        $image = new Imagick();
        $image->readImageBlob($png);
        $at = static fn (int $x, int $y): string => self::hexAt($image, (int) round($x * $ratio), (int) round($y * $ratio));

        self::assertSame('#ff0000', $at(90, 90), 'the decorative picture');
        self::assertSame('#ffffff', $at(45, 90), 'its 10 px border, inside the box');
        self::assertSame('#ff0000', $at(55, 90), 'and no wider (the object scale 2 does not double it)');
        self::assertSame('#0000ff', $at(42, 42), 'its corner is rounded off');

        self::assertSame('#00ff00', $at(270, 270), 'the user\'s picture in the slot');
        self::assertSame('#ffffff', $at(225, 270), 'the border runs along the SLOT frame');
        self::assertSame('#0000ff', $at(215, 270), 'nothing of the bigger picture outside the frame');
        self::assertSame('#0000ff', $at(222, 222), 'the slot\'s corner is rounded off');
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
