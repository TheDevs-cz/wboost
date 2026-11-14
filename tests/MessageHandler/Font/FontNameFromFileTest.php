<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Font;

use PHPUnit\Framework\TestCase;
use WBoost\Web\MessageHandler\Font\AddFontHandler;

/**
 * A font's names are read from the uploaded file. They end up in CSS
 * (`@font-face`), in a route parameter (`delete_font_face`) and in the
 * storage key — so they are one line of visible text, whatever the file says.
 */
final class FontNameFromFileTest extends TestCase
{
    public function testAnOrdinaryNameIsKeptAsItIs(): void
    {
        self::assertSame('Rubik Bold', AddFontHandler::nonEmpty('Rubik Bold'));
        self::assertSame('Příliš žluťoučký  Kůň (Display) 2.0', AddFontHandler::nonEmpty('  Příliš žluťoučký  Kůň (Display) 2.0 '));
        self::assertNull(AddFontHandler::nonEmpty(''));
        self::assertNull(AddFontHandler::nonEmpty("\n\t "));
        self::assertNull(AddFontHandler::nonEmpty(null));
    }

    public function testWhatCannotBeANameIsTakenOut(): void
    {
        // A line break ends a CSS string; what followed was read as CSS.
        self::assertSame("Evil } body { background: url(-evil.test-x) } @font-face { font-family: 'x", AddFontHandler::nonEmpty("Evil\n} body { background: url(/evil.test/x) } @font-face { font-family: 'x"));
        // A slash made the fonts page fail (the face name is a route parameter).
        self::assertSame('a-b-c', AddFontHandler::nonEmpty('a/b\\c'));
        self::assertSame('evil txt.exe', AddFontHandler::nonEmpty("\u{202E}evil\u{200B}\u{0}txt.exe"));
        self::assertSame(120, mb_strlen((string) AddFontHandler::nonEmpty(str_repeat('ž', 500))));
        // Bytes that are not UTF-8 do not survive as such.
        self::assertTrue(mb_check_encoding((string) AddFontHandler::nonEmpty("Font\xFF\xFEName"), 'UTF-8'));
    }
}
