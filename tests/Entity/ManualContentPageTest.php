<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Entity;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ContentPageBackground;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ContentVerticalAlign;
use WBoost\Web\Value\ManualContent\HeadingElement;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\RichTextDoc;
use WBoost\Web\Value\ManualContent\RichTextElement;
use WBoost\Web\Value\MockupPageLayout;

final class ManualContentPageTest extends TestCase
{
    public function testSegmentsArePaddedToTheLayout(): void
    {
        $segments = ManualContentPage::fitSegments(MockupPageLayout::Layout6, [new ContentSegment(ContentVerticalAlign::Bottom)]);

        self::assertCount(6, $segments);
        self::assertSame(ContentVerticalAlign::Bottom, $segments[0]->verticalAlign);
        self::assertSame([], $segments[5]->elements);
    }

    public function testElementsOfDroppedSegmentsMoveIntoTheLastSlot(): void
    {
        $a = new HeadingElement('a', 1, 'A');
        $b = new HeadingElement('b', 2, 'B');
        $c = new HeadingElement('c', 3, 'C');

        $segments = ManualContentPage::fitSegments(MockupPageLayout::Layout8, [
            new ContentSegment(elements: [$a]),
            new ContentSegment(elements: [$b]),
            new ContentSegment(elements: [$c]),
        ]);

        self::assertCount(2, $segments);
        self::assertSame([$a], $segments[0]->elements);
        self::assertSame([$b, $c], $segments[1]->elements);
    }

    public function testNoContentMeansNoPage(): void
    {
        self::assertFalse(self::hasContent([]));
        self::assertFalse(self::hasContent([new HeadingElement('a', 1, ''), new RichTextElement('b', RichTextDoc::empty())]));
        self::assertTrue(self::hasContent([new HeadingElement('a', 1, 'Úvod')]));
        self::assertTrue(self::hasContent([new RichTextElement('b', RichTextDoc::fromPlainText('Text'))]));
        self::assertTrue(self::hasContent([new ImageElement('c', 'manuals/00000000-0000-0000-0000-000000000001/x.png', ContentImageFit::Natural)]));
        // A page background alone is content too.
        self::assertTrue(self::hasContent([], new ContentPageBackground('manuals/00000000-0000-0000-0000-000000000001/bg.png')));
    }

    /**
     * @param list<\WBoost\Web\Value\ManualContent\ContentElement> $elements
     */
    private static function hasContent(array $elements, null|ContentPageBackground $background = null): bool
    {
        $page = (new \ReflectionClass(ManualContentPage::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ManualContentPage::class, 'segments'))->setValue($page, [new ContentSegment(elements: $elements)]);
        (new \ReflectionProperty(ManualContentPage::class, 'background'))->setValue($page, $background);

        return $page->hasContent();
    }
}
