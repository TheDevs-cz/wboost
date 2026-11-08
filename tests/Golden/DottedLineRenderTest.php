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
 * A dotted / dashed "Čára" exports as separate dots / dashes ALONG the line
 * (assets/editor/fabric_line_style.js, inlined into the render template) —
 * not as Fabric's dashed outline around the thin bar, which on the 2026-11
 * IDML import drew two rows of overlapping dots over a solid bar.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
#[Group('gotenberg')]
final class DottedLineRenderTest extends KernelTestCase
{
    /**
     * The stored prod object's shape: bar painted AND bordered in one colour,
     * a BORDER-width pattern ([0, 2 × strokeWidth]) — saved before the fix,
     * and it must render as dots without a data repair.
     */
    public function testALegacyDottedLineRendersAsSeparateRoundDots(): void
    {
        $image = $this->render('legacy-dotted', [
            self::line(['left' => 10, 'top' => 100, 'width' => 380, 'height' => 10, 'strokeWidth' => 10, 'strokeDashArray' => [0, 20]]),
        ]);

        // Fabric lays the bar out half a stroke width in from `left`/`top`
        // (its box includes the border), so the 380 × 10 bar spans x 15…394,
        // y 105…114 — exactly where the solid line would be painted.
        $runs = self::runs($image, 110, 0, 400, horizontal: true);

        // 380 px of 10 px dots, centre-to-centre ≈ 30 px, first and last flush
        // with the ends: (380 − 10) / 30 ≈ 12 gaps → 13 dots.
        self::assertCount(13, $runs, 'one run per dot along the centreline');
        self::assertSame(15, $runs[0][0], 'the first dot starts at the line start');
        self::assertSame(394, $runs[12][1], 'the last dot ends at the line end');
        foreach ($runs as [$start, $end]) {
            self::assertEqualsWithDelta(10, $end - $start + 1, 2, 'each dot is as wide as the line is thick');
        }
        self::assertGreaterThan(15, $runs[1][0] - $runs[0][1], 'clear gaps between the dots');

        // Round, not a bar: a dot's corner and the gap's centre are both empty,
        // and nothing spills outside the line's box (the old outline did).
        self::assertSame('#ffffff', self::hexAt($image, 15, 105), 'a dot has no square corner');
        self::assertSame('#ffffff', self::hexAt($image, 35, 110), 'the gap is empty — no solid bar under the dots');
        self::assertSame('#ffffff', self::hexAt($image, 20, 102), 'no outline above the bar');
        self::assertSame('#65bde6', self::hexAt($image, 20, 110), 'the dot is painted in the line colour');
    }

    public function testAVerticalDottedLineDotsDownItsLength(): void
    {
        $image = $this->render('vertical-dotted', [
            self::line(['left' => 200, 'top' => 20, 'width' => 8, 'height' => 360, 'strokeWidth' => 0, 'stroke' => null, 'strokeDashArray' => [0, 24]]),
        ]);

        $runs = self::runs($image, 204, 0, 400, horizontal: false);

        self::assertCount(16, $runs); // (360 − 8) / 24 ≈ 15 gaps
        self::assertSame(20, $runs[0][0]);
        self::assertSame(379, $runs[15][1]);
    }

    public function testADashedLineDrawsDashesAlongTheLine(): void
    {
        $image = $this->render('dashed', [
            self::line(['left' => 10, 'top' => 100, 'width' => 380, 'height' => 10, 'strokeDashArray' => [30, 20], 'strokeLineCap' => 'butt']),
        ]);

        $runs = self::runs($image, 110, 0, 400, horizontal: true);

        self::assertCount(8, $runs, '(380 + 20) / 50 = 8 dashes');
        self::assertSame(15, $runs[0][0]);
        self::assertSame(394, $runs[7][1]);
        self::assertSame('#65bde6', self::hexAt($image, 16, 106), 'a dash is square-ended, filling its box');
        self::assertSame('#ffffff', self::hexAt($image, 20, 102), 'no outline above the bar');
    }

    public function testASolidLineIsUntouched(): void
    {
        $image = $this->render('solid', [
            self::line(['left' => 10, 'top' => 100, 'width' => 380, 'height' => 10, 'strokeWidth' => 0, 'stroke' => null, 'strokeDashArray' => null]),
        ]);

        self::assertCount(1, self::runs($image, 105, 0, 400, horizontal: true));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function line(array $overrides): array
    {
        return $overrides + [
            'type' => 'Rect', 'originX' => 'left', 'originY' => 'top', 'rx' => 0, 'ry' => 0,
            'fill' => '#65bde6', 'stroke' => '#65bde6', 'strokeWidth' => 10, 'strokeUniform' => true,
            'strokeLineCap' => 'round', 'shapeKind' => 'line', 'inputId' => '99999999-9999-4999-8999-999999999999',
        ];
    }

    /**
     * @param list<array<string, mixed>> $objects
     */
    private function render(string $label, array $objects): Imagick
    {
        self::bootKernel();

        $variant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 400, 'height' => 400, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                ...$objects,
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];

        $png = $this->realRenderer()->renderToBytes($variant, new ResolvedInputOverrides([], []), ResolvedImageOverrides::none());

        $dump = getenv('DOTTED_LINE_RENDER_DUMP');
        if (is_string($dump) && $dump !== '') {
            file_put_contents(sprintf('%s/%s.png', $dump, $label), $png);
        }

        $image = new Imagick();
        $image->readImageBlob($png);

        return $image;
    }

    /**
     * Inclusive [start, end] runs of painted (non-white) pixels on one scan line.
     *
     * @return list<array{int, int}>
     */
    private static function runs(Imagick $image, int $at, int $from, int $to, bool $horizontal): array
    {
        $runs = [];
        $start = null;

        for ($i = $from; $i < $to; $i++) {
            $color = $image->getImagePixelColor($horizontal ? $i : $at, $horizontal ? $at : $i)->getColor();
            $painted = $color['r'] < 0xc0;

            if ($painted && $start === null) {
                $start = $i;
            } elseif (!$painted && $start !== null) {
                $runs[] = [$start, $i - 1];
                $start = null;
            }
        }

        if ($start !== null) {
            $runs[] = [$start, $to - 1];
        }

        return $runs;
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
            $projectDir . '/assets/editor/fabric_line_style.js',
        );
    }
}
