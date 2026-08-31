<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Imagick;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Sensiolabs\GotenbergBundle\Processor\InMemoryProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\EchoCapableTextInputs;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedImageOverrides;

/**
 * THE WYSIWYG proof of the client-side text echo: the composite the fill page
 * shows while typing — the text-transparent BASE render with the echo canvas
 * painted over it by assets/editor/fill_text_echo.js — must be pixel-equal to
 * the FULL server render of the same values.
 *
 * Both sides render through the same headless Chromium (Gotenberg), so this is
 * an ALGORITHM parity proof: same Fabric build, same shared modules, same
 * override pipeline, same reflow — executed once server-side (the render
 * template) and once through the echo painter + resolver mirror (the golden
 * harness inlines the exact scripts the browser loads). What it cannot cover
 * is a user's non-Chromium glyph rasterizer — which is why the settle render
 * remains the displayed truth at rest and the only exported pixels.
 *
 * Runs against the real Gotenberg service (group "gotenberg", excluded from
 * the default suite): `vendor/bin/phpunit --group gotenberg --filter Echo`.
 */
#[Group('gotenberg')]
final class FillEchoParityGoldenTest extends KernelTestCase
{
    private const string ID_HEADLINE = 'aaaaaaaa-1111-4111-8111-111111111111';
    private const string ID_RICH = 'bbbbbbbb-2222-4222-8222-222222222222';
    private const string ID_FLOW_A = 'cccccccc-3333-4333-8333-333333333333';
    private const string ID_FLOW_B = 'dddddddd-4444-4444-8444-444444444444';
    private const string ID_HIDABLE = 'eeeeeeee-5555-4555-8555-555555555555';

    public function testEchoCompositeMatchesTheFullServerRender(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $variant = $this->craftedVariant();
        $renderer = $this->realRenderer();

        $capable = (new EchoCapableTextInputs(new TextInputObjectBinder(new CanvasPlaceholderGeometry())))
            ->resolve($this->decodedCanvas($variant), $variant->inputs);
        self::assertSame(
            [self::ID_HEADLINE, self::ID_RICH, self::ID_FLOW_A, self::ID_FLOW_B, self::ID_HIDABLE],
            $capable,
            'every crafted input must be echo-capable or the golden proves less than it claims',
        );

        // Raw values exactly as the fill page's mirrors would carry them: a
        // too-long Czech headline (truncate-then-uppercase), a rich envelope,
        // a paragraph long enough to wrap and PUSH the container sibling, and
        // a hidden input (which must vanish on both sides).
        $rawValues = [
            self::ID_HEADLINE => ['value' => 'žluťoučký kůň pěl ďábelské ódy'],
            self::ID_RICH => ['value' => '{"runs":[{"text":"Bare"},{"text":"vný","color":"#e74c3c"},{"text":" text","underline":true}]}'],
            self::ID_FLOW_A => ['value' => "První odstavec, který se zalomí na více řádků, protože je opravdu hodně dlouhý a natlačí druhý text dolů."],
            self::ID_FLOW_B => ['value' => 'Posunutý řádek'],
            self::ID_HIDABLE => ['value' => 'Nikdy nevykreslit', 'hidden' => true],
        ];

        $overrides = (new ResolveTextOverrides())->resolve(
            $variant->inputs,
            array_map(
                static fn (array $raw): array => ['value' => $raw['value']] + (($raw['hidden'] ?? false) ? ['hide' => true] : []),
                $rawValues,
            ),
            truncateOverflow: true,
        );

        // Side one: the full server render — the settle / export pixels.
        $fullPng = $renderer->renderToBytes($variant, $overrides, ResolvedImageOverrides::none());

        // Side two: the echo base (same overrides for layout parity of
        // anything non-echoed; here everything is echoed so it is pure
        // background), PNG so the composite comparison is lossless.
        $basePng = $renderer->renderToBytes(
            $variant,
            $overrides,
            ResolvedImageOverrides::none(),
            transparentTextInputIds: $capable,
        );

        $compositePng = $this->renderHarness($variant, $capable, $rawValues, $basePng);

        [$fullImage, $compositeImage] = [new Imagick(), new Imagick()];
        $fullImage->readImageBlob($fullPng);
        $compositeImage->readImageBlob($compositePng);

        self::assertSame($fullImage->getImageWidth(), $compositeImage->getImageWidth());
        self::assertSame($fullImage->getImageHeight(), $compositeImage->getImageHeight());

        /** @var array{Imagick, float} $comparison */
        $comparison = $fullImage->compareImages($compositeImage, Imagick::METRIC_MEANSQUAREERROR);
        $distortion = $comparison[1];

        if ($distortion >= 0.0001) {
            // Leave the three artifacts behind for a human diff.
            $dir = sys_get_temp_dir() . '/echo-golden';
            @mkdir($dir, 0777, true);
            file_put_contents($dir . '/full.png', $fullPng);
            file_put_contents($dir . '/base.png', $basePng);
            file_put_contents($dir . '/composite.png', $compositePng);
        }

        self::assertLessThan(
            0.0001,
            $distortion,
            'echo composite must be pixel-equal to the full render (artifacts in ' . sys_get_temp_dir() . '/echo-golden)',
        );

        // Positive control: the base alone must NOT match the full render —
        // otherwise "pixel-equal" would be vacuously true of empty text.
        $baseImage = new Imagick();
        $baseImage->readImageBlob($basePng);
        /** @var array{Imagick, float} $baseComparison */
        $baseComparison = $fullImage->compareImages($baseImage, Imagick::METRIC_MEANSQUAREERROR);
        self::assertGreaterThan(
            0.0001,
            $baseComparison[1],
            'the transparent-text base must differ from the full render, or the echo painted nothing',
        );
    }

    /**
     * A controlled canvas on top of the fixture variant entity: full-bleed
     * background rect (unbound), five bound textboxes — plain with maxLength +
     * uppercase, rich, a container pair (reflow!), a hidable one — nothing
     * above them (all echo-capable by construction).
     */
    private function craftedVariant(): TemplateVariant
    {
        $repository = self::getContainer()->get(TemplateVariantRepository::class);
        $variant = $repository->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

        $width = $variant->dimension->width();
        $height = $variant->dimension->height();

        // Real saves always serialize a height (the designed wrap height) —
        // the z-guard refuses to reason about a heightless box.
        $textbox = static fn (float $left, float $top, float $boxWidth, float $fontSize, string $text): array => [
            'type' => 'Textbox',
            'left' => $left,
            'top' => $top,
            'width' => $boxWidth,
            'height' => round($fontSize * 1.16 * 1.13, 2),
            'fontSize' => $fontSize,
            'fontFamily' => 'Arial',
            'fill' => '#1a2b3c',
            'lineHeight' => 1.16,
            'originX' => 'left',
            'originY' => 'top',
            'text' => $text,
        ];

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => $width, 'height' => $height, 'fill' => '#e9eef5', 'originX' => 'left', 'originY' => 'top'],
                $textbox(80, 80, $width - 160.0, 64, 'Nadpis'),
                $textbox(80, 260, $width - 160.0, 40, 'Podtitulek'),
                $textbox(80, 480, $width - 160.0, 36, 'Odstavec A'),
                $textbox(80, 570, $width - 160.0, 36, 'Odstavec B'),
                $textbox(80, 900, $width - 160.0, 36, 'Volitelný'),
            ],
            'containers' => [
                ['id' => 'golden-flow', 'maxHeight' => 380.0, 'memberInputIds' => [self::ID_FLOW_A, self::ID_FLOW_B]],
            ],
        ], JSON_THROW_ON_ERROR);

        // Positional contract: the i-th visible Textbox binds inputs[i].
        $variant->inputs = [
            new EditorTextInput(self::ID_HEADLINE, 'Nadpis', 20, false, true, null, false),
            new EditorTextInput(self::ID_RICH, 'Podtitulek', null, false, false, null, false, richText: true),
            new EditorTextInput(self::ID_FLOW_A, 'Odstavec A', null, false, false, null, false),
            new EditorTextInput(self::ID_FLOW_B, 'Odstavec B', null, false, false, null, false),
            new EditorTextInput(self::ID_HIDABLE, 'Volitelný', null, false, false, null, true),
        ];
        $variant->imageInputs = [];
        $variant->backgroundImage = null;

        return $variant;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodedCanvas(TemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true, 512, JSON_THROW_ON_ERROR);
        \assert(is_array($decoded));

        return $decoded;
    }

    /**
     * @param list<string> $capable
     * @param array<string, array{value: string, hidden?: bool}> $rawValues
     */
    private function renderHarness(TemplateVariant $variant, array $capable, array $rawValues, string $basePng): string
    {
        $container = self::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $canvas = $this->decodedCanvas($variant);
        $rawObjects = $canvas['objects'];
        \assert(is_array($rawObjects));

        $binder = new TextInputObjectBinder(new CanvasPlaceholderGeometry());
        $objects = [];
        foreach ($binder->inputIdByObjectIndex($canvas, $variant->inputs) as $index => $inputId) {
            if (in_array($inputId, $capable, true)) {
                $objects[] = ['inputId' => $inputId, 'object' => $rawObjects[$index]];
            }
        }

        $inputRules = [];
        foreach ($variant->inputs as $input) {
            $inputRules[$input->inputId] = [
                'richText' => $input->richText,
                'lists' => $input->richText && $input->lists,
                'maxLength' => $input->maxLength,
                'uppercase' => $input->uppercase,
            ];
        }

        $payload = [
            'width' => $variant->dimension->width(),
            'height' => $variant->dimension->height(),
            'canvasHeight' => $variant->dimension->height(),
            'objects' => $objects,
            'containers' => $canvas['containers'],
            'inputs' => $inputRules,
        ];

        $inline = static function (string $path) use ($projectDir): string {
            $contents = file_get_contents($projectDir . '/' . $path);
            \assert(is_string($contents));

            return $contents;
        };

        /** @var GotenbergScreenshotInterface $gotenberg the test container
         *  hands back the traceable wrapper, which forwards the same API */
        $gotenberg = $container->get(GotenbergScreenshotInterface::class);
        $bytes = $gotenberg->html()
            ->content('api/echo_golden_harness.html.twig', [
                'width' => $variant->dimension->width(),
                'height' => $variant->dimension->height(),
                'base_data_uri' => 'data:image/png;base64,' . base64_encode($basePng),
                'payload' => $payload,
                'raw_values' => $rawValues,
                'font_faces' => [],
                'fabric_inline_script' => $inline('assets/fabric/fabric-7.3.1.min.js'),
                'break_word_inline_script' => $inline('assets/editor/fabric_break_word.js'),
                'container_layout_inline_script' => $inline('assets/editor/container_layout.js'),
                'rich_text_runs_inline_script' => $inline('assets/editor/rich_text_runs.js'),
                'rich_text_blocks_inline_script' => $inline('assets/editor/rich_text_blocks.js'),
                'fill_text_echo_inline_script' => $inline('assets/editor/fill_text_echo.js'),
            ])
            ->width($variant->dimension->width())
            ->height($variant->dimension->height())
            ->clip(true)
            ->waitForExpression('window.echoRendered === true')
            ->generate()
            ->processor(new InMemoryProcessor())
            ->process();
        \assert(is_string($bytes));

        return $bytes;
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
