<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Manual;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Manual\IntroContentPageMigration;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\RichTextElement;

/**
 * @covers \WBoost\Web\Services\Manual\IntroContentPageMigration
 */
final class IntroContentPageMigrationTest extends TestCase
{
    private const string MANUAL = '019159ad-87e7-73af-a176-a3def39dbab3';
    private const string IMAGE = 'manuals/019159ad-87e7-73af-a176-a3def39dbab3/intro-1726733314.png';

    public function testAManualWithoutPageTextsGetsTheDefaultNameAndOnlyItsPicture(): void
    {
        $plan = IntroContentPageMigration::plan(self::MANUAL, self::IMAGE, '{}');

        self::assertSame(self::MANUAL, $plan['manualId']);
        self::assertSame('Úvod', $plan['name']);
        self::assertSame('layout-7', $plan['layout']);

        $segments = ContentSegment::listFromArray(json_decode($plan['segments'], true));
        self::assertCount(1, $segments);
        self::assertCount(1, $segments[0]->elements);

        $image = $segments[0]->elements[0];
        self::assertInstanceOf(ImageElement::class, $image);
        self::assertSame(self::IMAGE, $image->path);
        self::assertSame('natural', $image->fit->value);
        self::assertSame('Úvod', $image->alt);
    }

    public function testOverridesBecomeTheNameAndARichTextElementBeforeThePicture(): void
    {
        $plan = IntroContentPageMigration::plan(self::MANUAL, self::IMAGE, (string) json_encode([
            'basic_logos' => ['title' => 'Jiná stránka', 'description' => null],
            'intro' => ['title' => 'Vítejte', 'description' => "První <b>řádek</b>\ndruhý\n\nDalší odstavec"],
        ]));

        self::assertSame('Vítejte', $plan['name']);

        $elements = ContentSegment::listFromArray(json_decode($plan['segments'], true))[0]->elements;
        self::assertCount(2, $elements);
        self::assertInstanceOf(RichTextElement::class, $elements[0]);
        self::assertSame('První <b>řádek</b>druhýDalší odstavec', $elements[0]->doc->plainText());
        self::assertCount(2, $elements[0]->doc->blocks());
        self::assertInstanceOf(ImageElement::class, $elements[1]);
    }

    public function testATitleOnlyOverrideAddsNoTextElement(): void
    {
        $plan = IntroContentPageMigration::plan(self::MANUAL, self::IMAGE, '{"intro": {"title": "Úvodem", "description": null}}');

        self::assertSame('Úvodem', $plan['name']);
        self::assertCount(1, ContentSegment::listFromArray(json_decode($plan['segments'], true))[0]->elements);
    }
}
