<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value\ManualContent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ContentVerticalAlign;
use WBoost\Web\Value\ManualContent\HeadingElement;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPagePayload;
use WBoost\Web\Value\ManualContent\RichTextElement;
use WBoost\Web\Value\MockupPageLayout;

final class ManualContentPagePayloadTest extends TestCase
{
    private const string IMAGE = 'manuals/00000000-0000-0000-0000-000000000001/content/a.png';

    public function testDecodesAWholePageAndRoundTrips(): void
    {
        $payload = ManualContentPagePayload::fromJson((string) json_encode([
            'name' => "  Úvod\n ",
            'layout' => 'layout-8',
            'segments' => [
                ['verticalAlign' => 'center', 'elements' => [
                    ['id' => 'h1', 'type' => 'heading', 'level' => 1, 'text' => " Vítejte\n v manuálu "],
                    ['id' => 'rt1', 'type' => 'richText', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ahoj']]]]]],
                ]],
                ['elements' => [
                    ['id' => 'img1', 'type' => 'image', 'path' => self::IMAGE, 'fit' => 'cover', 'alt' => 'Logo', 'url' => 'https://ignored.example/x.png'],
                ]],
            ],
        ]));

        self::assertSame('Úvod', $payload->name);
        self::assertSame(MockupPageLayout::Layout8, $payload->layout);
        self::assertSame(ContentVerticalAlign::Center, $payload->segments[0]->verticalAlign);
        self::assertSame(ContentVerticalAlign::Top, $payload->segments[1]->verticalAlign);

        $heading = $payload->segments[0]->elements[0];
        self::assertInstanceOf(HeadingElement::class, $heading);
        self::assertSame('Vítejte  v manuálu', $heading->text);
        self::assertInstanceOf(RichTextElement::class, $payload->segments[0]->elements[1]);

        $image = $payload->segments[1]->elements[0];
        self::assertInstanceOf(ImageElement::class, $image);
        self::assertSame(ContentImageFit::Cover, $image->fit);
        self::assertSame([
            'id' => 'img1', 'type' => 'image', 'path' => self::IMAGE, 'galleryImageId' => null, 'fit' => 'cover', 'alt' => 'Logo',
        ], $image->toArray());
        self::assertTrue($image->belongsToManual('00000000-0000-0000-0000-000000000001'));
        self::assertFalse($image->belongsToManual('00000000-0000-0000-0000-000000000002'));

        $stored = ContentSegment::listToArray($payload->segments);
        self::assertEquals($stored, ContentSegment::listToArray(ContentSegment::listFromArray($stored)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function invalidPayloads(): iterable
    {
        $page = static fn (array $elements, string $layout = 'layout-7'): array => ['name' => 'Úvod', 'layout' => $layout, 'segments' => [['elements' => $elements]]];

        yield 'not json' => ['{nope'];
        yield 'blank name' => [['name' => ' ', 'layout' => 'layout-7', 'segments' => [['elements' => []]]]];
        yield 'unknown layout' => [['name' => 'x', 'layout' => 'layout-99', 'segments' => [['elements' => []]]]];
        yield 'segment count ≠ slots' => [$page([], 'layout-8')];
        yield 'too many segments' => [['name' => 'x', 'layout' => 'layout-7', 'segments' => [['elements' => []], ['elements' => []]]]];
        yield 'bad alignment' => [['name' => 'x', 'layout' => 'layout-7', 'segments' => [['verticalAlign' => 'middle', 'elements' => []]]]];
        yield 'unknown element' => [$page([['id' => 'a', 'type' => 'video', 'src' => 'x']])];
        yield 'missing id' => [$page([['type' => 'heading', 'level' => 1, 'text' => 'x']])];
        yield 'malformed id' => [$page([['id' => '<b>', 'type' => 'heading', 'level' => 1, 'text' => 'x']])];
        yield 'heading level 4' => [$page([['id' => 'a', 'type' => 'heading', 'level' => 4, 'text' => 'x']])];
        yield 'heading too long' => [$page([['id' => 'a', 'type' => 'heading', 'level' => 1, 'text' => str_repeat('x', 301)]])];
        yield 'image url instead of key' => [$page([['id' => 'a', 'type' => 'image', 'path' => 'https://evil.example/x.png', 'fit' => 'natural']])];
        yield 'image traversal' => [$page([['id' => 'a', 'type' => 'image', 'path' => 'manuals/00000000-0000-0000-0000-000000000001/../../x.png', 'fit' => 'natural']])];
        // Gallery uploads (`file-upload/{projectId}/…`) are allowed since P3 — the save handler checks the project.
        yield 'image other namespace' => [$page([['id' => 'a', 'type' => 'image', 'path' => 'custom-templates/00000000-0000-0000-0000-000000000001/x.png', 'fit' => 'natural']])];
        yield 'image bad fit' => [$page([['id' => 'a', 'type' => 'image', 'path' => self::IMAGE, 'fit' => 'contain']])];
        yield 'image bad gallery id' => [$page([['id' => 'a', 'type' => 'image', 'path' => self::IMAGE, 'fit' => 'natural', 'galleryImageId' => 'x']])];
        yield 'rich text html' => [$page([['id' => 'a', 'type' => 'richText', 'doc' => '<p>x</p>']])];
        yield 'too many elements' => [$page(array_map(static fn (int $i): array => ['id' => 'h' . $i, 'type' => 'heading', 'level' => 1, 'text' => 'x'], range(1, 121)))];
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testRejects(array|string $payload): void
    {
        $this->expectException(InvalidManualContent::class);

        ManualContentPagePayload::fromJson(is_string($payload) ? $payload : (string) json_encode($payload));
    }

    public function testRejectsAnOversizedBody(): void
    {
        $this->expectException(InvalidManualContent::class);

        ManualContentPagePayload::fromJson(str_repeat(' ', ManualContentPagePayload::MAX_BYTES + 1));
    }
}
