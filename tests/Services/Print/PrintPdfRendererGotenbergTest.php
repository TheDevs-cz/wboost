<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print;

use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Print\BrandCmykPalette;
use WBoost\Web\Services\Print\PdfX1aWriter;
use WBoost\Web\Services\Print\PrintColorConverter;
use WBoost\Web\Services\Print\PrintPdfRenderer;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * End-to-end print PDF through the real Gotenberg: the document is then
 * rasterized back by Ghostscript into CMYK separations, so the assertions are
 * about INK — what a press would actually lay down — not about our own
 * intermediate images.
 *
 * `vendor/bin/phpunit --group gotenberg --filter PrintPdf`
 */
#[Group('gotenberg')]
final class PrintPdfRendererGotenbergTest extends KernelTestCase
{
    public function testBleedCropMarksAndBlackInkOverprint(): void
    {
        self::bootKernel();

        $variant = $this->craftedVariant();
        $pdf = $this->printRenderer()->render($variant, new ResolvedInputOverrides([], []), null, 'Tisk – test');

        self::assertStringStartsWith('%PDF-1.3', $pdf);
        self::assertStringContainsString('/GTS_PDFXConformance (PDF/X-1a:2001)', $pdf);
        self::assertStringContainsString('/OutputConditionIdentifier (FOGRA39)', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceCMYK', $pdf);
        self::assertStringNotContainsString('/DeviceRGB', $pdf);

        // A5 = 419.528 × 595.276 pt, bleed 3 mm = 8.504 pt, marks 8.504 + 27.
        self::assertMatchesRegularExpression('#/TrimBox \[ 35\.5039 35\.5039 455\.0315 630\.7795 \]#', $pdf);
        self::assertMatchesRegularExpression('#/BleedBox \[ 27 27 463\.5354 639\.2835 \]#', $pdf);
        self::assertMatchesRegularExpression('#/MediaBox \[ 0 0 490\.5354 666\.2835 \]#', $pdf);

        if (is_string(getenv('PRINT_PDF_DUMP'))) {
            file_put_contents((string) getenv('PRINT_PDF_DUMP'), $pdf);
        }

        $ink = $this->separations($pdf);

        // Inside the bleed (1 mm outside the trim, top-left): the full-bleed
        // blue rectangle, not paper.
        $bleed = $ink(35.5039 - 2.8, 630.7795 + 2.8);
        self::assertGreaterThan(50, $bleed['c'], 'artwork extends into the bleed');

        // Outside the bleed but on the media: paper, except the marks.
        $paper = $ink(10, 10);
        self::assertSame([0, 0, 0, 0], array_values($paper));

        // The crop mark: registration colour = every plate at 100 %.
        $mark = $ink(35.5039 - 8.504 - 13.5, 630.7795);
        self::assertGreaterThan(90, min($mark), 'crop mark prints on all four plates');

        // Inside the black headline: K100 OVERPRINTING the blue — the cyan of
        // the rectangle is still there under the black.
        $text = $ink(35.5039 + 40 / 300 * 72 + 6, 630.7795 - (60 + 90) / 300 * 72);
        self::assertGreaterThanOrEqual(97, $text['k'], 'black text prints as solid K');
        self::assertGreaterThan(50, $text['c'], 'black text overprints, it does not knock out the blue');

        // Inside the large black area: K100 KNOCKING OUT the blue (no ghosting).
        $area = $ink(35.5039 + 500 / 300 * 72, 630.7795 - 1800 / 300 * 72);
        self::assertGreaterThanOrEqual(97, $area['k'], 'black areas print as solid K too');
        self::assertLessThan(5, $area['c'], 'black areas knock the background out');
    }

    private function craftedVariant(): TemplateVariant
    {
        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));

        $variant->changePrintSettings(3.0, true);

        $reflection = new \ReflectionProperty(TemplateVariant::class, 'dimension');
        $reflection->setValue($variant, new \WBoost\Web\Value\TemplateDimension(
            \WBoost\Web\Value\DimensionUnit::Mm,
            148,
            210,
            null,
            3.0,
            true,
        ));

        $width = $variant->dimension->width();
        $height = $variant->dimension->height();
        $bleed = $variant->dimension->bleedPixels();

        $variant->editCanvas(json_encode([
            'version' => '7.3.1',
            'objects' => [
                // Full-bleed blue, drawn past the trim on every side.
                ['type' => 'Rect', 'left' => -$bleed, 'top' => -$bleed, 'width' => $width + 2 * $bleed, 'height' => $height + 2 * $bleed, 'fill' => '#17375f', 'originX' => 'left', 'originY' => 'top'],
                // A large black AREA (bottom half of the page, inside the trim).
                ['type' => 'Rect', 'left' => 100, 'top' => 1500, 'width' => 800, 'height' => 600, 'fill' => '#000000', 'originX' => 'left', 'originY' => 'top'],
                // A solid black block inside a big black word.
                ['type' => 'Textbox', 'left' => 40, 'top' => 60, 'width' => $width - 80, 'height' => 220, 'fontSize' => 200, 'fontFamily' => 'Arial', 'fontWeight' => 'bold', 'fill' => '#000000', 'text' => '██████', 'originX' => 'left', 'originY' => 'top'],
            ],
        ], JSON_THROW_ON_ERROR), [], null);

        return $variant;
    }

    /**
     * @return \Closure(float, float): array{c: int, m: int, y: int, k: int}
     *   ink percentages at a PDF point (origin bottom-left)
     */
    private function separations(string $pdf): \Closure
    {
        $dir = sys_get_temp_dir() . '/print-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/in.pdf", $pdf);

        $dpi = 72;
        exec(sprintf('gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=tiff32nc -r%d -sOutputFile=%s %s 2>&1', $dpi, escapeshellarg("$dir/out.tif"), escapeshellarg("$dir/in.pdf")), $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        $image = new \Imagick("$dir/out.tif");
        $height = $image->getImageHeight();

        return static function (float $x, float $y) use ($image, $height): array {
            $pixel = $image->getImagePixelColor((int) round($x), (int) round($height - $y));

            return [
                'c' => (int) round($pixel->getColorValue(\Imagick::COLOR_CYAN) * 100),
                'm' => (int) round($pixel->getColorValue(\Imagick::COLOR_MAGENTA) * 100),
                'y' => (int) round($pixel->getColorValue(\Imagick::COLOR_YELLOW) * 100),
                'k' => (int) round($pixel->getColorValue(\Imagick::COLOR_BLACK) * 100),
            ];
        };
    }

    private function printRenderer(): PrintPdfRenderer
    {
        $container = self::getContainer();
        $geometry = new CanvasPlaceholderGeometry();
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        $renderer = new TemplateVariantImageRenderer(
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

        return new PrintPdfRenderer(
            $renderer,
            $container->get(BrandCmykPalette::class),
            $container->get(PrintColorConverter::class),
            new PdfX1aWriter(),
            new TextInputObjectBinder($geometry),
            $container->get(ClockInterface::class),
        );
    }
}
