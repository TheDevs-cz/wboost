<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Services\WeeklyMenuPdfGenerator;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * The headless renderer has no network access and reads no file but the page
 * it was handed (docs/architecture/templates-render.md, "Renderer isolation"):
 * a render page that links a stylesheet, a font, a script or a picture by URL
 * would come out without it — silently. Everything is embedded.
 */
final class RenderPagesLinkNothingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function renderTemplates(): iterable
    {
        yield 'template variant' => ['api/template_variant_render.html.twig'];
        yield 'AI scan font faces' => ['api/font_faces_render.html.twig'];
        yield 'calendar page' => ['calendar/headless_render.html.twig'];
        yield 'calendar PDF' => ['calendar/headless_print.html.twig'];
        yield 'weekly menu PDF' => ['pdf/weekly_menu.html.twig'];
    }

    #[DataProvider('renderTemplates')]
    public function testTheTemplateNamesNoAddress(string $template): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/' . $template);

        // Twig comments may talk about addresses; the page may not hold one.
        $source = (string) preg_replace('/\{#.*?#\}/s', '', $source);

        self::assertDoesNotMatchRegularExpression('~(?:https?|wss?|ftp):/|\bfile:/~i', $source, $template . ' names an address — the renderer cannot load it');
        self::assertDoesNotMatchRegularExpression('~<link\b[^>]*\bhref=|<script\b[^>]*\bsrc=["\'](?!data:)~i', $source, $template . ' links a file');
    }

    public function testTheWeeklyMenuPageCarriesItsFontAsData(): void
    {
        self::bootKernel();

        $menu = self::getContainer()->get(WeeklyMenuRepository::class)->get(Uuid::fromString(TestDataFixture::WEEKLY_MENU_1_ID));
        $generator = self::getContainer()->get(WeeklyMenuPdfGenerator::class);
        $fontFaces = (new \ReflectionMethod($generator, 'fontFaces'))->invoke($generator);

        self::assertIsArray($fontFaces);
        self::assertCount(9, $fontFaces, 'the nine subsets Google serves for Roboto');

        $html = self::getContainer()->get(Environment::class)->render('pdf/weekly_menu.html.twig', [
            'menu' => $menu,
            'font_faces' => $fontFaces,
        ]);

        self::assertSame(9, substr_count($html, '@font-face'));
        self::assertSame(9, substr_count($html, "src: url(data:font/woff2;base64,"));
        self::assertStringContainsString('unicode-range: U+0100-02BA', $html, 'latin-ext (Czech) with its range, unescaped');
        self::assertDoesNotMatchRegularExpression('~url\((?!data:)~', $html);
        self::assertDoesNotMatchRegularExpression('~(?:href|src)=["\']?(?:https?:)?//~i', $html);

        // the embedded files are the fonts themselves, whole
        foreach ($fontFaces as $face) {
            self::assertIsArray($face);
            self::assertIsString($face['src']);
            self::assertSame('wOF2', substr((string) base64_decode(substr($face['src'], strlen('data:font/woff2;base64,')), true), 0, 4));
        }
    }
}
