<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\EditorTextInput;

/**
 * @covers \WBoost\Web\Value\EditorTextInput
 */
final class EditorTextInputTest extends TestCase
{
    private const string ID = '11111111-1111-4111-8111-111111111111';

    public function testAllowedFontsRoundTripThroughTheJsonShape(): void
    {
        $input = new EditorTextInput(self::ID, 'headline', null, false, false, null, false, allowedFonts: ['Rubik (Rubik Bold)', 'Lato (Lato Italic)']);

        self::assertTrue($input->offersFontChoice());
        self::assertSame(['Rubik (Rubik Bold)', 'Lato (Lato Italic)'], $input->toArray()['allowedFonts']);

        $restored = EditorTextInput::fromArray($input->toArray());

        self::assertSame(['Rubik (Rubik Bold)', 'Lato (Lato Italic)'], $restored->allowedFonts);
    }

    public function testAllowedFontsDefaultToNoChoiceAndAreReadDefensively(): void
    {
        $legacy = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false]);

        self::assertSame([], $legacy->allowedFonts);
        self::assertFalse($legacy->offersFontChoice());

        $garbage = EditorTextInput::fromArray([
            'inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false,
            'allowedFonts' => ['Rubik (Rubik Bold)', '', 42, null, 'Rubik (Rubik Bold)', '  '],
        ]);

        self::assertSame(['Rubik (Rubik Bold)'], $garbage->allowedFonts);

        $notAList = EditorTextInput::fromArray([
            'inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false,
            'allowedFonts' => 'Rubik (Rubik Bold)',
        ]);

        self::assertSame([], $notAList->allowedFonts);
    }

    public function testConfiguredFacesAreTheFlagOrTheirPicks(): void
    {
        $legacy = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false, 'richText' => true]);
        self::assertFalse($legacy->fontChoice);
        self::assertFalse($legacy->restrictsFaces(), 'an unconfigured rich input keeps its whole-family offer');

        $designedOnly = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false, 'richText' => true, 'fontChoice' => true]);
        self::assertTrue($designedOnly->restrictsFaces());
        self::assertFalse($designedOnly->offersFontChoice(), 'no picks = nothing to switch to');

        $picked = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false, 'allowedFonts' => ['Rubik (Rubik Bold)']]);
        self::assertTrue($picked->restrictsFaces(), 'picks made before the flag existed still configure the offer');
        self::assertTrue($picked->toArray()['fontChoice'] === false && $picked->offersFontChoice());
    }

    public function testColourAllowlistIsTriStateAndNormalized(): void
    {
        $any = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false]);
        self::assertNull($any->allowedColors);
        self::assertNull($any->toArray()['allowedColors']);

        $locked = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false, 'allowedColors' => []]);
        self::assertSame([], $locked->allowedColors);

        $list = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false, 'allowedColors' => ['#C8102E', 'abc', 'not-a-colour', '#c8102e', 12]]);
        self::assertSame(['#c8102e', '#aabbcc'], $list->allowedColors);
        self::assertSame(['#c8102e', '#aabbcc'], EditorTextInput::fromArray($list->toArray())->allowedColors);

        $garbage = EditorTextInput::fromArray(['inputId' => self::ID, 'name' => 'x', 'maxLength' => null, 'locked' => false, 'allowedColors' => 'red']);
        self::assertNull($garbage->allowedColors);
    }
}
