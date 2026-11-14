<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\RenderHtmlTesting;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Security regression (2026-11-13), the AI-scan side: a candidate design is
 * what a model read off somebody's picture. A text `</script><script>…` in it
 * ran as script in the renderer's Chromium. The candidate goes scanner API
 * `render` → preflight → {@see \WBoost\Web\Mcp\Design\CandidateRenderer} → the
 * shared renderer; this test follows the same way and reads the page the real
 * renderer builds for what the candidate renderer handed over.
 */
final class ScannerRenderEscapingTest extends WebTestCase
{
    use AiScanTesting;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileTexts(): iterable
    {
        foreach (RenderHtmlTesting::hostileTexts() as $name => $text) {
            yield $name => [$text];
        }
    }

    #[DataProvider('hostileTexts')]
    public function testATextOfACandidateCannotLeaveItsScriptBlock(string $hostile): void
    {
        $build = self::running($this->client);

        $baseline = $this->pageOfCandidate($build, 1, 'harmless');
        $html = $this->pageOfCandidate($build, 2, $hostile);

        RenderHtmlTesting::assertDataStaysData($baseline, $html, 'scan candidate');

        $canvas = RenderHtmlTesting::embedded($html, 'canvasJson');
        self::assertIsArray($canvas);
        self::assertStringContainsString(
            json_encode($hostile, JSON_THROW_ON_ERROR),
            json_encode($canvas, JSON_THROW_ON_ERROR),
            'the candidate still holds the text as it was read',
        );
    }

    /**
     * @param array{id: string, run: int, lease: string, job: array<string, mixed>} $build
     */
    private function pageOfCandidate(array $build, int $seq, string $text): string
    {
        $design = self::design($text);
        $elements = $design['elements'];
        self::assertIsArray($elements);
        $headline = $elements[1];
        self::assertIsArray($headline);
        // …and as the sample of a fillable text: a typed override in the page.
        $headline['input'] = ['name' => 'Nadpis', 'maxLength' => 400, 'sampleValue' => $text];
        $elements[1] = $headline;
        $design['elements'] = $elements;

        $answer = self::scanner($this->client, 'POST', $build['id'] . '/render', ['run' => $build['run'], 'seq' => $seq, 'design' => $design], $build['lease']);
        self::assertSame($seq, $answer['json']['candidate'] ?? null, 'the candidate rendered: ' . $answer['raw']);

        $container = $this->client->getContainer();
        // In the test env the renderer interface aliases to the fake; PHPStan
        // reads the dev container, where it is the real implementation.
        $fake = $container->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $fake);
        $variant = $fake->lastVariant;
        $overrides = $fake->lastOverrides;
        self::assertInstanceOf(TemplateVariant::class, $variant);
        self::assertInstanceOf(ResolvedInputOverrides::class, $overrides);

        return RenderHtmlTesting::html($container->get(TemplateVariantImageRenderer::class), $variant, $overrides);
    }
}
