<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\AiScan\Template\TransientVariant;
use WBoost\Web\Services\Editor\RendererProbe;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * The 2026-11-13 proof of concept, against a real headless Chromium: a text
 * that closes the render page's `<script>` and brings its own. The injected
 * script paints the canvas magenta and reports the page as rendered — before
 * the fix the picture came back magenta. Now the text is drawn as glyphs.
 *
 * `gotenberg` group: needs a renderer. The page is self-contained (no
 * picture, no project font), so the CI job with a Gotenberg service runs it.
 */
#[Group('gotenberg')]
final class RenderInjectionGotenbergTest extends KernelTestCase
{
    public function testATextThatClosesTheScriptBlockIsDrawnNotRun(): void
    {
        self::bootKernel();

        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        $variant = self::getContainer()->get(TransientVariant::class)->for($project, 600, 300);

        $injected = "x</script><script>var c=document.getElementById('c'),x=c.getContext('2d');x.fillStyle='#ff00ff';x.fillRect(0,0,c.width,c.height);window.canvasRendered=true;</script><!--";

        $variant->editCanvas(json_encode([
            'version' => '7.3.1',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => 600, 'height' => 300, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                ['type' => 'Textbox', 'text' => $injected, 'left' => 20, 'top' => 120, 'width' => 560, 'fontSize' => 14, 'fill' => '#000000', 'fontFamily' => 'sans-serif', 'originX' => 'left', 'originY' => 'top'],
            ],
        ], JSON_THROW_ON_ERROR), [], null, []);

        $bytes = self::getContainer()->get(TemplateVariantImageRenderer::class)->renderToBytes($variant, new ResolvedInputOverrides([], []), format: RenderImageFormat::Png);

        $image = new \Imagick();
        $image->readImageBlob($bytes);
        self::assertSame(600, $image->getImageWidth());

        // Far from the text: white where the design is white. Magenta = the injected script ran.
        foreach ([[10, 10], [590, 10], [300, 60], [10, 290]] as [$x, $y]) {
            $pixel = $image->getImagePixelColor($x, $y)->getColor();
            self::assertSame([255, 255, 255], [$pixel['r'], $pixel['g'], $pixel['b']], sprintf('the pixel at %d, %d', $x, $y));
        }

        // …and the text itself is on the picture: dark pixels on its line.
        $dark = 0;

        for ($x = 20; $x < 580; $x += 2) {
            for ($y = 118; $y < 150; $y += 2) {
                $pixel = $image->getImagePixelColor($x, $y)->getColor();
                $dark += $pixel['r'] < 100 && $pixel['g'] < 100 && $pixel['b'] < 100 ? 1 : 0;
            }
        }

        self::assertGreaterThan(40, $dark, 'the hostile text is drawn as text');
    }

    public function testTheProbeTellsAWorkingRendererFromAnAbsentOne(): void
    {
        self::bootKernel();

        self::assertTrue(self::getContainer()->get(RendererProbe::class)->responds());
        self::assertFalse((new RendererProbe(HttpClient::createForBaseUri('http://127.0.0.1:9')))->responds());
    }
}
