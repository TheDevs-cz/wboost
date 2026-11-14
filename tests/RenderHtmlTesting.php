<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use PHPUnit\Framework\Assert;
use ReflectionMethod;
use Sensiolabs\GotenbergBundle\Builder\AbstractBuilder;
use Sensiolabs\GotenbergBundle\Builder\BuilderFileInterface;
use Sensiolabs\GotenbergBundle\Builder\ValueObject\RenderedPart;
use Sensiolabs\GotenbergBundle\Debug\Builder\TraceableBuilder;
use Sensiolabs\GotenbergBundle\Enumeration\Part;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * The HTML the headless renderer is handed, without a render: the REAL
 * {@see TemplateVariantImageRenderer} builds its Gotenberg request (template
 * and all) and the test reads the page out of it — no Chromium, no network.
 *
 * Why this exists: the page embeds server data (the canvas, the texts a user
 * typed, font names, container names) inside a `<script>` block. Data that
 * can close that block runs as script in the renderer's Chromium — 2026-11-13,
 * a text `</script><script>…` did. {@see assertDataStaysData()} is the guard.
 */
final class RenderHtmlTesting
{
    /**
     * Texts that end a `<script>` block, open a comment / CDATA / attribute,
     * or end a JS statement, when they are embedded without escaping.
     *
     * @return array<string, string>
     */
    public static function hostileTexts(): array
    {
        return [
            'closing script tag' => "a</script><script>window.__x=1;fetch('http://127.0.0.1:8999/x')</script>",
            'closing tag, odd case and space' => 'b</ScRiPt ><SCRIPT>window.__x=2</SCRIPT >',
            'html comment + script' => 'c<!--<script>window.__x=3',
            'js line separators' => "d\u{2028}window.__x=4;\u{2029}window.__x=5;",
            'cdata end' => 'e]]><script>window.__x=6</script>',
            'quotes and backslash' => "f\";window.__x=7;//' \\\";window.__x=8;// `\${window.__x=9}`",
            'entity' => 'g&lt;/script&gt;&#60;script&#62;window.__x=10',
            'style and svg' => 'h</style><svg onload=window.__x=11><img src=x onerror=window.__x=12>',
        ];
    }

    public static function html(
        TemplateVariantImageRenderer $renderer,
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
    ): string {
        $method = new ReflectionMethod($renderer, 'buildScreenshot');
        $builder = $method->invoke($renderer, $variant, $overrides, $imageOverrides, false, null);
        Assert::assertInstanceOf(BuilderFileInterface::class, $builder);

        // With the profiler on (the test environment) the builder is wrapped.
        if ($builder instanceof TraceableBuilder) {
            $builder = $builder->getInner();
        }

        Assert::assertInstanceOf(AbstractBuilder::class, $builder);

        $part = $builder->getBodyBag()->get(Part::Body->value);
        Assert::assertInstanceOf(RenderedPart::class, $part);

        return $part->body;
    }

    /**
     * The page built from hostile data has exactly the markup of the page
     * built from harmless data: the same number of script / style / comment
     * openers and closers, and none of the hostile text's own markup.
     */
    public static function assertDataStaysData(string $baseline, string $html, string $what = ''): void
    {
        foreach (['<script', '</script', '<style', '</style', '<!--', '-->', ']]>', '<svg', '<img', '<iframe'] as $token) {
            Assert::assertSame(
                substr_count(strtolower($baseline), $token),
                substr_count(strtolower($html), $token),
                sprintf('%s: the number of "%s" in the render page changed with the data — data broke out of its script block.', $what, $token),
            );
        }

        // U+2028 / U+2029 end a line in older JS parsers: never raw in the page.
        Assert::assertStringNotContainsString("\u{2028}", $html, $what);
        Assert::assertStringNotContainsString("\u{2029}", $html, $what);
        Assert::assertStringNotContainsString('window.__x=1;fetch(\'http', $html, $what . ': the single quotes of a hostile text must be escaped');
    }

    /**
     * The value of `const <name> = …;` in the page's data block, decoded — the
     * proof that escaping changed the bytes only, not what the script reads.
     */
    public static function embedded(string $html, string $name): mixed
    {
        Assert::assertSame(1, preg_match('/^\s*const ' . preg_quote($name, '/') . ' = (.*);$/m', $html, $match), 'no `const ' . $name . '` in the render page');

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
