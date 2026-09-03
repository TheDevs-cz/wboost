<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\RichTextFontOption;
use WBoost\Web\Value\RichTextOptions;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveTextOverrides
 */
final class ResolveTextOverridesTest extends TestCase
{
    private const INPUT_ID = '11111111-1111-4111-8111-111111111111';

    public function testThrowsByDefaultWhenValueExceedsMaxLength(): void
    {
        $this->expectException(BadRequestHttpException::class);

        (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 5)],
            [self::INPUT_ID => 'abcdefgh'],
        );
    }

    public function testTruncatesToMaxLengthWhenTruncateOverflowRequested(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 5)],
            [self::INPUT_ID => 'abcdefgh'],
            truncateOverflow: true,
        );

        self::assertSame('abcde', $result->texts[self::INPUT_ID]);
    }

    public function testTruncationCountsMultibyteCharacters(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 3)],
            [self::INPUT_ID => 'ěščřž'],
            truncateOverflow: true,
        );

        self::assertSame('ěšč', $result->texts[self::INPUT_ID]);
    }

    public function testTruncationHappensBeforeUppercasing(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 3, uppercase: true)],
            [self::INPUT_ID => 'abcdef'],
            truncateOverflow: true,
        );

        self::assertSame('ABC', $result->texts[self::INPUT_ID]);
    }

    public function testValueWithinLimitIsLeftUntouched(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 5)],
            [self::INPUT_ID => 'abc'],
            truncateOverflow: true,
        );

        self::assertSame('abc', $result->texts[self::INPUT_ID]);
    }

    public function testFontChoiceIsValidatedAgainstTheInputsOwnOptions(): void
    {
        $options = new RichTextOptions(
            fonts: [$this->fontOption('Rubik (Rubik Regular)'), $this->fontOption('Rubik (Rubik Bold)')],
            colors: [],
            fontsByInput: [self::INPUT_ID => [$this->fontOption('Rubik (Rubik Bold)')]],
        );

        // An allowed pick rides along with the text (and alone).
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(uppercase: true)],
            [self::INPUT_ID => ['value' => 'hi', 'fontFamily' => 'Rubik (Rubik Bold)']],
            richTextOptions: $options,
        );
        self::assertSame('HI', $result->texts[self::INPUT_ID]);
        self::assertSame([self::INPUT_ID => 'Rubik (Rubik Bold)'], $result->fonts);

        // The union allows Regular, THIS input does not — strict 400 with the
        // input's own list, lenient drop.
        try {
            (new ResolveTextOverrides())->resolve(
                [$this->input()],
                [self::INPUT_ID => ['value' => 'hi', 'fontFamily' => 'Rubik (Rubik Regular)']],
                richTextOptions: $options,
            );
            self::fail('Expected font_not_allowed');
        } catch (InvalidRichTextValue $exception) {
            self::assertSame('font_not_allowed', $exception->errorCode);
            self::assertSame(['allowedFonts' => ['Rubik (Rubik Bold)']], $exception->context);
        }

        $lenient = (new ResolveTextOverrides())->resolve(
            [$this->input()],
            [self::INPUT_ID => ['value' => 'hi', 'fontFamily' => 'Rubik (Rubik Regular)']],
            truncateOverflow: true,
            richTextOptions: $options,
        );
        self::assertSame('hi', $lenient->texts[self::INPUT_ID]);
        self::assertSame([], $lenient->fonts);

        // "" is the select's "výchozí" option; null skips; no options = no check.
        $blank = (new ResolveTextOverrides())->resolve([$this->input()], [self::INPUT_ID => ['value' => 'x', 'fontFamily' => '']], richTextOptions: $options);
        self::assertSame([], $blank->fonts);
        $unchecked = (new ResolveTextOverrides())->resolve([$this->input()], [self::INPUT_ID => ['value' => 'x', 'fontFamily' => 'Anything']]);
        self::assertSame([self::INPUT_ID => 'Anything'], $unchecked->fonts);

        $this->expectException(BadRequestHttpException::class);
        (new ResolveTextOverrides())->resolve([$this->input()], [self::INPUT_ID => ['value' => 'x', 'fontFamily' => 12]]);
    }

    public function testFontPickAloneKeepsTheSampleAsTheText(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(sampleValue: 'Sample copy')],
            [self::INPUT_ID => ['fontFamily' => 'Rubik (Rubik Bold)']],
        );

        self::assertSame('Sample copy', $result->texts[self::INPUT_ID]);
        self::assertSame([self::INPUT_ID => 'Rubik (Rubik Bold)'], $result->fonts);

        // Without a sample the designed canvas text stays (no text override).
        $designed = (new ResolveTextOverrides())->resolve(
            [$this->input()],
            [self::INPUT_ID => ['fontFamily' => 'Rubik (Rubik Bold)']],
        );

        self::assertSame([], $designed->texts);
        self::assertSame([self::INPUT_ID => 'Rubik (Rubik Bold)'], $designed->fonts);
    }

    public function testRichRunsResolveIntoPlainConcatAndStyledRuns(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->richInput()],
            [self::INPUT_ID => ['runs' => [
                ['text' => 'Hello '],
                ['text' => 'world', 'fontFamily' => 'Roboto (Roboto Bold)', 'color' => '#C8102E', 'underline' => true],
            ]]],
            richTextOptions: $this->options(),
        );

        self::assertSame('Hello world', $result->texts[self::INPUT_ID]);
        self::assertSame(
            [
                ['text' => 'Hello ', 'fontFamily' => null, 'color' => null, 'underline' => false],
                ['text' => 'world', 'fontFamily' => 'Roboto (Roboto Bold)', 'color' => '#c8102e', 'underline' => true],
            ],
            $result->richTexts[self::INPUT_ID]->toArray(),
        );
    }

    public function testUnstyledRunsDegradeToAPlainOverride(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->richInput()],
            [self::INPUT_ID => ['runs' => [['text' => 'Hello']]]],
            richTextOptions: $this->options(),
        );

        self::assertSame('Hello', $result->texts[self::INPUT_ID]);
        self::assertArrayNotHasKey(self::INPUT_ID, $result->richTexts);
    }

    public function testUnstyledNewlineEnvelopePreservesLineBreaksAsPlainOverride(): void
    {
        // The web WYSIWYG smuggles multi-line values through the string mirror
        // as a {"runs":[...]} envelope (an <input> would otherwise strip the
        // literal "\n"). Even with no styling the envelope must be honored and
        // the newline must survive into the plain override the renderer uses.
        $envelope = '{"runs":[{"text":"first\nsecond\n\nfourth","fontFamily":null,"color":null,"underline":false}]}';

        $result = (new ResolveTextOverrides())->resolve(
            [$this->richInput()],
            [self::INPUT_ID => $envelope],
            truncateOverflow: true,
        );

        self::assertSame("first\nsecond\n\nfourth", $result->texts[self::INPUT_ID]);
        self::assertArrayNotHasKey(self::INPUT_ID, $result->richTexts);
    }

    public function testEnvelopeStringIsDetectedOnlyForRichInputs(): void
    {
        $envelope = '{"runs":[{"text":"styled","underline":true}]}';

        $richResult = (new ResolveTextOverrides())->resolve(
            [$this->richInput()],
            [self::INPUT_ID => $envelope],
            truncateOverflow: true,
        );

        self::assertSame('styled', $richResult->texts[self::INPUT_ID]);
        self::assertTrue($richResult->richTexts[self::INPUT_ID]->runs[0]->underline);

        // The same string on a PLAIN input stays a literal string value.
        $plainResult = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 100)],
            [self::INPUT_ID => $envelope],
            truncateOverflow: true,
        );

        self::assertSame($envelope, $plainResult->texts[self::INPUT_ID]);
        self::assertSame([], $plainResult->richTexts);
    }

    public function testEnvelopeInsideValueObjectIsDetectedForRichInputs(): void
    {
        // The web fill flow wraps the mirror string as { value, hide }.
        $result = (new ResolveTextOverrides())->resolve(
            [$this->richInput(hidable: true)],
            [self::INPUT_ID => ['value' => '{"runs":[{"text":"x","underline":true}]}', 'hide' => true]],
            truncateOverflow: true,
        );

        self::assertSame('x', $result->texts[self::INPUT_ID]);
        self::assertTrue($result->richTexts[self::INPUT_ID]->runs[0]->underline);
        self::assertTrue($result->hidden[self::INPUT_ID]);
    }

    public function testRunsOnNonRichInputThrowInStrictMode(): void
    {
        try {
            (new ResolveTextOverrides())->resolve(
                [$this->input(maxLength: 100)],
                [self::INPUT_ID => ['runs' => [['text' => 'x']]]],
            );
            self::fail('Expected InvalidRichTextValue');
        } catch (InvalidRichTextValue $exception) {
            self::assertSame('rich_text_not_allowed', $exception->errorCode);
        }
    }

    public function testRunsOnNonRichInputDegradeToPlainTextInLenientMode(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 100)],
            [self::INPUT_ID => ['runs' => [['text' => 'Hello '], ['text' => 'world', 'underline' => true]]]],
            truncateOverflow: true,
        );

        self::assertSame('Hello world', $result->texts[self::INPUT_ID]);
        self::assertSame([], $result->richTexts);
    }

    public function testRunsAndValueTogetherThrowInStrictMode(): void
    {
        try {
            (new ResolveTextOverrides())->resolve(
                [$this->richInput()],
                [self::INPUT_ID => ['runs' => [['text' => 'x']], 'value' => 'y']],
            );
            self::fail('Expected InvalidRichTextValue');
        } catch (InvalidRichTextValue $exception) {
            self::assertSame('invalid_rich_text', $exception->errorCode);
        }
    }

    public function testRichMaxLengthThrowsInStrictModeOnConcatenation(): void
    {
        $this->expectException(BadRequestHttpException::class);

        (new ResolveTextOverrides())->resolve(
            [$this->richInput(maxLength: 5)],
            [self::INPUT_ID => ['runs' => [['text' => 'abc'], ['text' => 'defg', 'underline' => true]]]],
        );
    }

    public function testRichTruncationWalksRunsThenUppercasesPerRun(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->richInput(maxLength: 4, uppercase: true)],
            [self::INPUT_ID => ['runs' => [['text' => 'abc'], ['text' => 'def', 'underline' => true]]]],
            truncateOverflow: true,
        );

        self::assertSame('ABCD', $result->texts[self::INPUT_ID]);
        self::assertSame(
            [
                ['text' => 'ABC', 'fontFamily' => null, 'color' => null, 'underline' => false],
                ['text' => 'D', 'fontFamily' => null, 'color' => null, 'underline' => true],
            ],
            $result->richTexts[self::INPUT_ID]->toArray(),
        );
    }

    public function testRichFontOutsideWhitelistThrowsInStrictMode(): void
    {
        try {
            (new ResolveTextOverrides())->resolve(
                [$this->richInput()],
                [self::INPUT_ID => ['runs' => [['text' => 'x', 'fontFamily' => 'Comic Sans (Regular)']]]],
                richTextOptions: $this->options(),
            );
            self::fail('Expected InvalidRichTextValue');
        } catch (InvalidRichTextValue $exception) {
            self::assertSame('font_not_allowed', $exception->errorCode);
        }
    }

    public function testRichFontOutsideWhitelistIsStrippedInLenientMode(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->richInput()],
            [self::INPUT_ID => ['runs' => [['text' => 'x', 'fontFamily' => 'Comic Sans (Regular)', 'underline' => true]]]],
            truncateOverflow: true,
            richTextOptions: $this->options(),
        );

        self::assertNull($result->richTexts[self::INPUT_ID]->runs[0]->fontFamily);
        self::assertTrue($result->richTexts[self::INPUT_ID]->runs[0]->underline);
    }

    public function testRichValueOnLockedInputIsIgnored(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->richInput(locked: true)],
            [self::INPUT_ID => ['runs' => [['text' => 'x', 'underline' => true]]]],
        );

        self::assertSame([], $result->texts);
        self::assertSame([], $result->richTexts);
    }

    public function testSampleValueRendersWhenInputOmitted(): void
    {
        $input = new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Headline',
            maxLength: null,
            locked: false,
            uppercase: false,
            description: null,
            hidable: false,
            sampleValue: 'Vzorový nadpis',
        );

        $resolved = (new ResolveTextOverrides())->resolve([$input], []);

        self::assertSame('Vzorový nadpis', $resolved->texts[self::INPUT_ID]);
    }

    public function testProvidedValueBeatsTheSample(): void
    {
        $input = new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Headline',
            maxLength: null,
            locked: false,
            uppercase: false,
            description: null,
            hidable: false,
            sampleValue: 'Vzorový nadpis',
        );

        $resolved = (new ResolveTextOverrides())->resolve([$input], [self::INPUT_ID => 'Skutečný text']);

        self::assertSame('Skutečný text', $resolved->texts[self::INPUT_ID]);
    }

    public function testProvidedEmptyStringSuppressesTheSample(): void
    {
        $input = new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Headline',
            maxLength: null,
            locked: false,
            uppercase: false,
            description: null,
            hidable: false,
            sampleValue: 'Vzorový nadpis',
        );

        $resolved = (new ResolveTextOverrides())->resolve([$input], [self::INPUT_ID => '']);

        self::assertSame('', $resolved->texts[self::INPUT_ID]);
    }

    public function testRichSampleEnvelopeWithListsResolvesLeniently(): void
    {
        $input = new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Checklist',
            maxLength: null,
            locked: false,
            uppercase: false,
            description: null,
            hidable: false,
            richText: true,
            lists: true,
            sampleValue: '{"runs":[{"text":"Intro\nItem"}],"lines":["p","ul"]}',
        );

        // Strict mode (API export) + omitted input: the sample parses
        // LENIENTLY, so a stored sample can never 400 the consumer.
        $resolved = (new ResolveTextOverrides())->resolve([$input], [], truncateOverflow: false);

        self::assertSame("Intro
Item", $resolved->texts[self::INPUT_ID]);
        self::assertTrue($resolved->richTexts[self::INPUT_ID]->hasLists());
        self::assertSame(['p', 'ul'], $resolved->richTexts[self::INPUT_ID]->lineTypes);
    }

    public function testChecklistValueResolvesWithCheckboxLines(): void
    {
        $input = $this->checklistInput();

        $resolved = (new ResolveTextOverrides())->resolve(
            [$input],
            [self::INPUT_ID => '{"runs":[{"text":"First\nSecond"}],"lines":["cbx","cb"]}'],
        );

        self::assertSame("First\nSecond", $resolved->texts[self::INPUT_ID]);
        self::assertSame(['cbx', 'cb'], $resolved->richTexts[self::INPUT_ID]->lineTypes);
    }

    public function testChecklistWithAllCapabilitiesOffIgnoresProvidedOverride(): void
    {
        $input = $this->checklistInput(
            toggle: false,
            editText: false,
            add: false,
            remove: false,
            sampleValue: '{"runs":[{"text":"Fixed item"}],"lines":["cbx"]}',
        );

        // The provided value must NOT win — the input is read-only, the
        // admin sample renders instead.
        $resolved = (new ResolveTextOverrides())->resolve(
            [$input],
            [self::INPUT_ID => '{"runs":[{"text":"Hacked"}],"lines":["cb"]}'],
        );

        self::assertSame('Fixed item', $resolved->texts[self::INPUT_ID]);
        self::assertSame(['cbx'], $resolved->richTexts[self::INPUT_ID]->lineTypes);
    }

    public function testChecklistWithAnyCapabilityAcceptsProvidedOverride(): void
    {
        $input = $this->checklistInput(
            toggle: true,
            editText: false,
            add: false,
            remove: false,
            sampleValue: '{"runs":[{"text":"Fixed item"}],"lines":["cb"]}',
        );

        $resolved = (new ResolveTextOverrides())->resolve(
            [$input],
            [self::INPUT_ID => '{"runs":[{"text":"Fixed item"}],"lines":["cbx"]}'],
        );

        self::assertSame(['cbx'], $resolved->richTexts[self::INPUT_ID]->lineTypes);
    }

    private function checklistInput(
        bool $toggle = true,
        bool $editText = true,
        bool $add = true,
        bool $remove = true,
        null|string $sampleValue = null,
    ): EditorTextInput {
        return new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Checklist',
            maxLength: null,
            locked: false,
            uppercase: false,
            description: null,
            hidable: false,
            richText: true,
            lists: true,
            listCheckboxes: true,
            checklist: true,
            checklistAdd: $add,
            checklistRemove: $remove,
            checklistEditText: $editText,
            checklistToggle: $toggle,
            sampleValue: $sampleValue,
        );
    }

    private function input(null|int $maxLength = null, bool $uppercase = false, null|string $sampleValue = null): EditorTextInput
    {
        return new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Headline',
            maxLength: $maxLength,
            locked: false,
            uppercase: $uppercase,
            description: null,
            hidable: false,
            sampleValue: $sampleValue,
        );
    }

    private function fontOption(string $family): RichTextFontOption
    {
        [$fontName, $faceName] = explode(' (', rtrim($family, ')'), 2);

        return new RichTextFontOption($family, $fontName, $faceName, 400, 'normal', 'https://assets.test/' . $faceName);
    }

    private function richInput(
        null|int $maxLength = null,
        bool $uppercase = false,
        bool $locked = false,
        bool $hidable = false,
    ): EditorTextInput {
        return new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Headline',
            maxLength: $maxLength,
            locked: $locked,
            uppercase: $uppercase,
            description: null,
            hidable: $hidable,
            richText: true,
        );
    }

    private function options(): RichTextOptions
    {
        return new RichTextOptions(
            fonts: [
                new RichTextFontOption(
                    family: 'Roboto (Roboto Bold)',
                    fontName: 'Roboto',
                    faceName: 'Roboto Bold',
                    weight: 700,
                    style: 'normal',
                    url: 'https://assets.test/fonts/roboto-bold.woff2',
                ),
            ],
            colors: ['#c8102e'],
        );
    }
}
