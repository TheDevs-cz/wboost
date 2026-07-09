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

    private function input(int $maxLength, bool $uppercase = false): EditorTextInput
    {
        return new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Headline',
            maxLength: $maxLength,
            locked: false,
            uppercase: $uppercase,
            description: null,
            hidable: false,
        );
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
