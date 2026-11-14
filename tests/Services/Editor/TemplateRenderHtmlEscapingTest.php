<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\RenderHtmlTesting;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Security regression (2026-11-13): the render page embeds the canvas and the
 * overrides inside a `<script>`. A text `</script><script>…` closed the block
 * and ran in the renderer's Chromium (local files, internal services, outside
 * hosts). Whatever a design or a fill holds must stay DATA in that page.
 *
 * No Chromium here — the page is read out of the request the real renderer
 * builds ({@see RenderHtmlTesting}); the pixels are the `gotenberg` group's.
 *
 * @covers \WBoost\Web\Services\Editor\TemplateVariantImageRenderer
 */
final class TemplateRenderHtmlEscapingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileTexts(): iterable
    {
        foreach (RenderHtmlTesting::hostileTexts() as $name => $text) {
            yield $name => [$text];
        }
    }

    /**
     * The text sits everywhere a designer or a filling user can put one: the
     * canvas object's text, its font family and name, a container's name, a
     * typed override, a font override — and an input id (a JSON key).
     */
    #[DataProvider('hostileTexts')]
    public function testATextOfADesignOrAFillCannotLeaveItsScriptBlock(string $hostile): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(TemplateVariantImageRenderer::class);

        $baseline = RenderHtmlTesting::html($renderer, $this->variantHolding('harmless'), $this->overrides('harmless'));
        $html = RenderHtmlTesting::html($renderer, $this->variantHolding($hostile), $this->overrides($hostile));

        RenderHtmlTesting::assertDataStaysData($baseline, $html, 'canvas + overrides');

        // …and the script still reads exactly what was typed.
        $textboxes = 0;

        foreach (self::objects(RenderHtmlTesting::embedded($html, 'canvasJson')) as $object) {
            if (self::isTextbox($object)) {
                $textboxes++;
                self::assertSame($hostile, $object['text'] ?? null);
                self::assertSame($hostile, $object['fontFamily'] ?? null);
            }
        }

        self::assertGreaterThan(0, $textboxes);

        $textOverrides = RenderHtmlTesting::embedded($html, 'textOverrides');
        self::assertIsArray($textOverrides);
        self::assertSame($hostile, $textOverrides[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        self::assertSame('x', $textOverrides[$hostile] ?? null, 'a hostile KEY is escaped like a value');

        $fontOverrides = RenderHtmlTesting::embedded($html, 'fontOverrides');
        self::assertIsArray($fontOverrides);
        self::assertSame($hostile, $fontOverrides[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID] ?? null);

        $containers = RenderHtmlTesting::embedded($html, 'containers');
        self::assertIsArray($containers);
        self::assertStringContainsString(json_encode($hostile, JSON_THROW_ON_ERROR), json_encode($containers, JSON_THROW_ON_ERROR));
    }

    /**
     * The template on its own, with EVERY variable it embeds hostile — also
     * the ones whose producers validate today (font face names come from
     * uploaded font files, list configs from the input's settings).
     */
    #[DataProvider('hostileTexts')]
    public function testEveryVariableTheRenderTemplateEmbedsIsEscaped(string $hostile): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $variant = $this->fixtureVariant();

        $context = static fn (string $text): array => [
            'variant' => $variant,
            'canvas_json' => json_encode(['objects' => [['type' => 'Textbox', 'text' => $text]]], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'font_faces' => [['family' => $text, 'src' => 'data:font/ttf;base64,AAAA' . $text]],
            'text_overrides' => [$text => $text],
            'rich_text_overrides' => [$text => ['runs' => [['text' => $text, 'fontFamily' => $text, 'color' => $text, 'underline' => false]], 'lines' => [$text]]],
            'font_overrides' => [$text => $text],
            'list_configs' => [$text => ['marker' => $text, 'bulletImageSrc' => $text]],
            'hidden_overrides' => [$text => true],
            'containers' => [['id' => $text, 'name' => $text, 'members' => [$text]]],
            'strict_container_overflow' => false,
            'fabric_inline_script' => '',
            'break_word_inline_script' => '',
            'line_style_inline_script' => '',
            'container_layout_inline_script' => '',
            'rich_text_runs_inline_script' => '',
            'rich_text_blocks_inline_script' => '',
            'bleed_px' => 0,
        ];

        $baseline = $twig->render('api/template_variant_render.html.twig', $context('harmless'));
        $html = $twig->render('api/template_variant_render.html.twig', $context($hostile));

        RenderHtmlTesting::assertDataStaysData($baseline, $html, 'template variables');

        foreach (['fontFaces', 'textOverrides', 'richTextOverrides', 'fontOverrides', 'listConfigs', 'hiddenOverrides', 'containers'] as $name) {
            $value = RenderHtmlTesting::embedded($html, $name);
            self::assertStringContainsString(
                json_encode($hostile, JSON_THROW_ON_ERROR),
                json_encode($value, JSON_THROW_ON_ERROR),
                $name . ' must decode to the text as given',
            );
        }
    }

    /**
     * The rule the fix rests on, stated on the source: nothing in the render
     * template is embedded raw unless it is an inline script file or JSON
     * encoded with the HEX flags.
     */
    public function testNothingInTheRenderTemplateIsEmbeddedRawWithoutHexFlags(): void
    {
        self::bootKernel();
        $source = (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/templates/api/template_variant_render.html.twig');

        self::assertGreaterThan(0, preg_match_all('/\{\{(.*?)\}\}/s', $source, $matches));

        foreach ($matches[1] as $expression) {
            if (!str_contains($expression, '|raw')) {
                continue;
            }

            $safe = preg_match('/^\s*\w+_inline_script(\|default\(\'\'\))?\|raw\s*$/', $expression) === 1
                || trim($expression) === 'canvas_json|raw'
                || (str_contains($expression, 'json_encode(') && str_contains($expression, 'JSON_HEX_TAG') && str_contains($expression, 'JSON_HEX_AMP') && str_contains($expression, 'JSON_HEX_APOS') && str_contains($expression, 'JSON_HEX_QUOT'));

            self::assertTrue($safe, 'raw embedding without the HEX flags in the render template: {{' . $expression . '}}');
        }
    }

    private function overrides(string $text): ResolvedInputOverrides
    {
        return new ResolvedInputOverrides(
            [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => $text, $text => 'x'],
            [$text => true],
            [],
            [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID => $text],
        );
    }

    /**
     * The fixture variant with the text in every string a design holds.
     */
    private function variantHolding(string $text): TemplateVariant
    {
        $variant = clone $this->fixtureVariant();
        $canvas = json_decode($variant->canvas, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($canvas);

        $objects = [];

        foreach (self::objects($canvas) as $object) {
            if (self::isTextbox($object)) {
                $object = ['text' => $text, 'fontFamily' => $text, 'name' => $text] + $object;
            }

            $objects[] = $object;
        }

        $containers = [];

        foreach (is_array($canvas['containers'] ?? null) ? $canvas['containers'] : [] as $container) {
            self::assertIsArray($container);
            $containers[] = ['name' => $text] + $container;
        }

        self::assertNotSame([], $containers, 'the fixture variant has a container');
        $canvas['objects'] = $objects;
        $canvas['containers'] = $containers;

        $variant->editCanvas(json_encode($canvas, JSON_THROW_ON_ERROR), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);

        return $variant;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function objects(mixed $canvas): array
    {
        self::assertIsArray($canvas);
        self::assertIsArray($canvas['objects'] ?? null);
        $objects = [];

        foreach ($canvas['objects'] as $object) {
            self::assertIsArray($object);
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function isTextbox(array $object): bool
    {
        return is_string($object['type'] ?? null) && strtolower($object['type']) === 'textbox';
    }

    private function fixtureVariant(): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
    }
}
