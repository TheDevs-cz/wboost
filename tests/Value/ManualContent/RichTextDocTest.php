<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value\ManualContent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Value\ManualContent\ManualContentStyles;
use WBoost\Web\Value\ManualContent\RichTextDoc;

final class RichTextDocTest extends TestCase
{
    public function testAcceptsTheEditorSchemaAndKeepsItCanonical(): void
    {
        $doc = RichTextDoc::fromArray([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    // Marks out of order and duplicated → canonical order, once.
                    ['type' => 'text', 'text' => 'Ahoj', 'marks' => [
                        ['type' => 'color', 'attrs' => ['color' => '#C8102E']],
                        ['type' => 'size', 'attrs' => ['size' => 24, 'unit' => 'dropped']],
                        ['type' => 'bold'],
                        ['type' => 'bold'],
                        ['type' => 'link', 'attrs' => ['href' => 'https://wboost.cz', 'title' => 'dropped']],
                    ]],
                    ['type' => 'hard_break'],
                    ['type' => 'text', 'text' => 'svete', 'unknownKey' => 'dropped'],
                ]],
                ['type' => 'ordered_list', 'attrs' => ['order' => 3], 'content' => [
                    ['type' => 'list_item', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]],
                        ['type' => 'bullet_list', 'content' => [
                            ['type' => 'list_item', 'content' => [['type' => 'paragraph']]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        self::assertSame([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Ahoj', 'marks' => [
                        ['type' => 'link', 'attrs' => ['href' => 'https://wboost.cz']],
                        ['type' => 'bold'],
                        ['type' => 'size', 'attrs' => ['size' => 24]],
                        ['type' => 'color', 'attrs' => ['color' => '#c8102e']],
                    ]],
                    ['type' => 'hard_break'],
                    ['type' => 'text', 'text' => 'svete'],
                ]],
                ['type' => 'ordered_list', 'attrs' => ['order' => 3], 'content' => [
                    ['type' => 'list_item', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]],
                        ['type' => 'bullet_list', 'content' => [
                            ['type' => 'list_item', 'content' => [['type' => 'paragraph']]],
                        ]],
                    ]],
                ]],
            ],
        ], $doc->toArray());
        self::assertFalse($doc->isEmpty());
    }

    public function testAnEmptyDocumentBecomesOneEmptyParagraph(): void
    {
        $doc = RichTextDoc::fromArray(['type' => 'doc', 'content' => []]);

        self::assertSame(['type' => 'doc', 'content' => [['type' => 'paragraph']]], $doc->toArray());
        self::assertTrue($doc->isEmpty());
    }

    public function testControlCharactersAndNewlinesNeverReachTheText(): void
    {
        $doc = RichTextDoc::fromArray(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "a\nb\x00c\td"], ['type' => 'text', 'text' => "\x01"]]],
        ]]);

        self::assertSame([['type' => 'text', 'text' => 'a bc d']], $doc->blocks()[0]['content']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDocuments(): iterable
    {
        $paragraph = static fn (array $inline): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $inline]]];
        $marked = static fn (array $mark): array => $paragraph([['type' => 'text', 'text' => 'x', 'marks' => [$mark]]]);

        yield 'not a doc' => [['type' => 'paragraph']];
        yield 'not an array' => ['<p>hi</p>'];
        yield 'html node' => [['type' => 'doc', 'content' => [['type' => 'html', 'text' => '<script>alert(1)</script>']]]];
        yield 'heading node' => [['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 1]]]]];
        yield 'image node' => [$paragraph([['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']]])];
        yield 'text as block' => [['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'x']]]];
        yield 'list in paragraph' => [$paragraph([['type' => 'bullet_list', 'content' => []]])];
        yield 'empty list' => [['type' => 'doc', 'content' => [['type' => 'bullet_list', 'content' => []]]]];
        yield 'list item outside list' => [['type' => 'doc', 'content' => [['type' => 'list_item', 'content' => [['type' => 'paragraph']]]]]];
        yield 'list item without paragraph' => [['type' => 'doc', 'content' => [['type' => 'bullet_list', 'content' => [['type' => 'list_item', 'content' => []]]]]]];
        yield 'bad list start' => [['type' => 'doc', 'content' => [['type' => 'ordered_list', 'attrs' => ['order' => -1], 'content' => [['type' => 'list_item', 'content' => [['type' => 'paragraph']]]]]]]];
        yield 'text without text' => [$paragraph([['type' => 'text']])];
        yield 'unknown mark' => [$marked(['type' => 'script'])];
        yield 'style mark' => [$marked(['type' => 'style', 'attrs' => ['css' => 'background:url(javascript:x)']])];
        yield 'javascript link' => [$marked(['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']])];
        yield 'javascript link mixed case' => [$marked(['type' => 'link', 'attrs' => ['href' => 'JaVaScRiPt:alert(1)']])];
        yield 'javascript link with tab' => [$marked(['type' => 'link', 'attrs' => ['href' => "java\tscript:alert(1)"]])];
        yield 'data link' => [$marked(['type' => 'link', 'attrs' => ['href' => 'data:text/html,<script>alert(1)</script>']])];
        yield 'protocol-relative link' => [$marked(['type' => 'link', 'attrs' => ['href' => '//evil.example']])];
        yield 'link with a space' => [$marked(['type' => 'link', 'attrs' => ['href' => 'https://a.cz/" onmouseover="x']])];
        yield 'link without href' => [$marked(['type' => 'link'])];
        yield 'colour name' => [$marked(['type' => 'color', 'attrs' => ['color' => 'red']])];
        yield 'colour with css' => [$marked(['type' => 'color', 'attrs' => ['color' => '#fff; background: url(x)']])];
        yield 'font with newline' => [$marked(['type' => 'font', 'attrs' => ['family' => "Rubik\n"]])];
        yield 'font empty' => [$marked(['type' => 'font', 'attrs' => ['family' => '']])];
        yield 'size as css' => [$marked(['type' => 'size', 'attrs' => ['size' => '24px; background: url(x)']])];
        yield 'size as string number' => [$marked(['type' => 'size', 'attrs' => ['size' => '24']])];
        yield 'size fractional' => [$marked(['type' => 'size', 'attrs' => ['size' => 24.5]])];
        yield 'size too small' => [$marked(['type' => 'size', 'attrs' => ['size' => 7]])];
        yield 'size too big' => [$marked(['type' => 'size', 'attrs' => ['size' => 97]])];
        yield 'size missing' => [$marked(['type' => 'size'])];
    }

    #[DataProvider('invalidDocuments')]
    public function testRejects(mixed $document): void
    {
        $this->expectException(InvalidManualContent::class);

        RichTextDoc::fromArray($document);
    }

    public function testRejectsTooDeepNesting(): void
    {
        $node = ['type' => 'paragraph'];

        for ($i = 0; $i < 10; $i++) {
            $node = ['type' => 'bullet_list', 'content' => [['type' => 'list_item', 'content' => [['type' => 'paragraph'], $node]]]];
        }

        $this->expectException(InvalidManualContent::class);

        RichTextDoc::fromArray(['type' => 'doc', 'content' => [$node]]);
    }

    public function testAcceptsHttpHttpsAndMailtoLinks(): void
    {
        self::assertTrue(RichTextDoc::isAllowedHref('https://wboost.cz/x?a=1#b'));
        self::assertTrue(RichTextDoc::isAllowedHref('http://wboost.cz'));
        self::assertTrue(RichTextDoc::isAllowedHref('mailto:info@wantoo.cz'));
        self::assertFalse(RichTextDoc::isAllowedHref('ftp://wboost.cz'));
        self::assertFalse(RichTextDoc::isAllowedHref('wboost.cz'));
        self::assertFalse(RichTextDoc::isAllowedHref('https://'));
    }

    public function testPlainTextBecomesParagraphsAndLineBreaks(): void
    {
        $doc = RichTextDoc::fromPlainText("První <b>řádek</b>\r\ndruhý\n\n\nDalší odstavec\n");

        self::assertSame([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'První <b>řádek</b>'],
                ['type' => 'hard_break'],
                ['type' => 'text', 'text' => 'druhý'],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Další odstavec']]],
        ], $doc->blocks());
        // What it builds is itself a valid document.
        self::assertSame($doc->toArray(), RichTextDoc::fromArray($doc->toArray())->toArray());
    }

    public function testRestrictStylesDropsFontsAndColoursTheManualDoesNotAllow(): void
    {
        $doc = RichTextDoc::fromArray(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'font', 'attrs' => ['family' => 'Rubik (Rubik Bold)']], ['type' => 'color', 'attrs' => ['color' => '#c8102e']]]],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'bold'], ['type' => 'font', 'attrs' => ['family' => 'Comic Sans']], ['type' => 'color', 'attrs' => ['color' => '#00ff00']]]],
        ]]]]);

        $styles = new ManualContentStyles(
            [['family' => 'Rubik (Rubik Bold)', 'label' => 'Rubik Bold']],
            [['color' => '#c8102e', 'name' => 'Červená']],
        );

        self::assertSame([
            ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'font', 'attrs' => ['family' => 'Rubik (Rubik Bold)']], ['type' => 'color', 'attrs' => ['color' => '#c8102e']]]],
            ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'bold']]],
        ], $doc->restrictStyles($styles)->blocks()[0]['content']);
    }
}
