<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Manual;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Manual\RichTextHtmlRenderer;
use WBoost\Web\Value\ManualContent\ManualContentStyles;
use WBoost\Web\Value\ManualContent\RichTextDoc;

final class RichTextHtmlRendererTest extends TestCase
{
    private ManualContentStyles $styles;

    protected function setUp(): void
    {
        $this->styles = new ManualContentStyles(
            [
                ['family' => 'Rubik (Rubik Bold)', 'label' => 'Rubik Bold'],
                // A font name a user typed — must not break out of the CSS string / attribute.
                ['family' => 'Evil", x: "</span><script>alert(1)</script>', 'label' => 'Evil'],
            ],
            [['color' => '#c8102e', 'name' => 'Červená']],
        );
    }

    public function testRendersTheAllowListedStructure(): void
    {
        $html = $this->render([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Tučně', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' a '],
                ['type' => 'text', 'text' => 'vše', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://wboost.cz/?a=1&b=2']],
                    ['type' => 'bold'], ['type' => 'italic'], ['type' => 'underline'],
                    ['type' => 'font', 'attrs' => ['family' => 'Rubik (Rubik Bold)']],
                    ['type' => 'size', 'attrs' => ['size' => 32]],
                    ['type' => 'color', 'attrs' => ['color' => '#C8102E']],
                ]],
                ['type' => 'hard_break'],
                ['type' => 'text', 'text' => 'konec'],
            ]],
            ['type' => 'paragraph'],
            ['type' => 'bullet_list', 'content' => [
                ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'jedna']]]]],
            ]],
            ['type' => 'ordered_list', 'attrs' => ['order' => 4], 'content' => [
                ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'čtyři']]]]],
            ]],
        ]);

        self::assertSame(
            '<p><strong>Tučně</strong> a '
            . '<a href="https://wboost.cz/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer"><strong><em><u><span style="font-family: &quot;Rubik (Rubik Bold)&quot;; font-size: 32px; color: #c8102e">vše</span></u></em></strong></a>'
            . '<br>konec</p>'
            . '<p><br></p>'
            . '<ul><li><p>jedna</p></li></ul>'
            . '<ol start="4"><li><p>čtyři</p></li></ol>',
            $html,
        );
    }

    public function testEscapesText(): void
    {
        $html = $this->render([['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => '<script>alert("x")</script><img src=x onerror=alert(1)> & \'quote\''],
        ]]]);

        self::assertSame('<p>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt; &amp; &apos;quote&apos;</p>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testAQuoteInAnAllowedLinkCannotOpenAnAttribute(): void
    {
        $html = $this->render([['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.cz/"onmouseover="alert(1)']]]],
        ]]]);

        self::assertSame('<p><a href="https://a.cz/&quot;onmouseover=&quot;alert(1)" target="_blank" rel="noopener noreferrer">x</a></p>', $html);
    }

    public function testAFontNameCannotEscapeTheCssStringOrTheAttribute(): void
    {
        $html = $this->render([['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'font', 'attrs' => ['family' => 'Evil", x: "</span><script>alert(1)</script>']]]],
        ]]]);

        self::assertStringNotContainsString('<script', $html);
        self::assertSame(
            '<p><span style="font-family: &quot;Evil\\22 , x\\3A  \\22 \\3C \\2F span\\3E \\3C script\\3E alert(1)\\3C \\2F script\\3E &quot;">x</span></p>',
            $html,
        );
    }

    /**
     * The renderer re-checks every value itself — a document that never went
     * through RichTextDoc's validation (a hand-edited row, a future bug)
     * still cannot produce markup outside the allow-list.
     */
    public function testUnvalidatedNodesAndMarksProduceNoMarkup(): void
    {
        $doc = $this->unvalidated([
            ['type' => 'html', 'content' => [['type' => 'text', 'text' => 'nope']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'image', 'attrs' => ['src' => 'x']],
                ['type' => 'text', 'text' => 'js', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
                ['type' => 'text', 'text' => 'css', 'marks' => [['type' => 'color', 'attrs' => ['color' => 'red;background:url(javascript:x)']]]],
                ['type' => 'text', 'text' => 'style', 'marks' => [['type' => 'style', 'attrs' => ['css' => 'x']]]],
                ['type' => 'text', 'text' => 'font', 'marks' => [['type' => 'font', 'attrs' => ['family' => 'Not In Manual']]]],
                ['type' => 'text', 'text' => 'colour', 'marks' => [['type' => 'color', 'attrs' => ['color' => '#00ff00']]]],
                ['type' => 'text', 'text' => 'size', 'marks' => [['type' => 'size', 'attrs' => ['size' => '9px;background:url(x)']]]],
                ['type' => 'text', 'text' => 'huge', 'marks' => [['type' => 'size', 'attrs' => ['size' => 4000]]]],
            ]],
            ['type' => 'ordered_list', 'attrs' => ['order' => '1" onclick="x'], 'content' => [['type' => 'paragraph']]],
        ]);

        self::assertSame('<p>jscssstylefontcoloursizehuge</p><ol></ol>', (new RichTextHtmlRenderer())->render($doc, $this->styles));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function render(array $blocks): string
    {
        return (new RichTextHtmlRenderer())->render(RichTextDoc::fromArray(['type' => 'doc', 'content' => $blocks]), $this->styles);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function unvalidated(array $blocks): RichTextDoc
    {
        $reflection = new \ReflectionClass(RichTextDoc::class);
        $doc = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('doc')->setValue($doc, ['type' => 'doc', 'content' => $blocks]);

        return $doc;
    }
}
