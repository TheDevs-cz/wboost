<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidDesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DslParser;

/**
 * No string of a design holds a control character other than a line break or
 * a tab (2026-11-13: a NUL in a scan's candidate was a 500 — Postgres cannot
 * store U+0000 in a JSONB column). One rule in the parser, so it holds for
 * `set_design`, `preview_design` and the AI scan alike.
 */
final class DslParserControlCharactersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'NUL' => ["a\u{0}b", 'U+0000'];
        yield 'bell' => ["a\u{7}b", 'U+0007'];
        yield 'vertical tab' => ["a\u{B}b", 'U+000B'];
        yield 'escape' => ["a\u{1B}[31mb", 'U+001B'];
        yield 'delete' => ["a\u{7F}b", 'U+007F'];
        yield 'C1 next line' => ["a\u{85}b", 'U+0085'];
    }

    #[DataProvider('refused')]
    public function testAControlCharacterIsRefusedWhereverItSits(string $value, string $named): void
    {
        $places = [
            'elements[0].text' => static fn (array $d): array => self::set($d, ['elements', 0, 'text'], $value),
            'elements[0].font' => static fn (array $d): array => self::set($d, ['elements', 0, 'font'], $value),
            'elements[0].input.name' => static fn (array $d): array => self::set($d, ['elements', 0, 'input', 'name'], $value),
            'elements[0].input.sampleValue' => static fn (array $d): array => self::set($d, ['elements', 0, 'input', 'sampleValue'], $value),
            'elements[0].input.allowedFonts[0]' => static fn (array $d): array => self::set($d, ['elements', 0, 'input', 'allowedFonts'], [$value]),
        ];

        foreach ($places as $path => $put) {
            $violations = self::reject($put(self::document()))->violations;
            $ours = array_values(array_filter($violations, static fn ($violation): bool => str_contains($violation->message, 'control character')));

            self::assertNotSame([], $ours, $path);
            self::assertSame($path, $ours[0]->path);
            self::assertStringContainsString($named, $ours[0]->message);

            // What goes back (and may be stored with a candidate) never carries the character itself.
            foreach ($violations as $violation) {
                self::assertSame(0, preg_match(DslParser::CONTROL_CHARACTER_PATTERN, $violation->message . $violation->path), $path);
            }
        }
    }

    public function testAKeyHoldingAControlCharacterIsRefusedToo(): void
    {
        $document = self::document();
        $document["no\u{0}te"] = 'x';

        $violations = self::reject($document)->violations;

        self::assertNotSame([], array_filter($violations, static fn ($violation): bool => str_contains($violation->message, 'U+0000')));

        foreach ($violations as $violation) {
            self::assertStringNotContainsString("\u{0}", $violation->message . $violation->path);
        }
    }

    public function testLineBreaksTabsAndFormattingCharactersOfRealTextStay(): void
    {
        // Line feed, carriage return + line feed (a text pasted on Windows),
        // tab; a zero-width joiner (emoji), right-to-left marks (Hebrew),
        // the line separator U+2028 — all legitimate in free text.
        $text = "První řádek\nDruhý\r\nTřetí\tse zarážkou 👩\u{200D}💻 \u{202B}שלום\u{202C} konec\u{2028}";
        $document = self::set(self::set(self::document(), ['elements', 0, 'text'], $text), ['elements', 0, 'input', 'sampleValue'], $text);

        $parsed = DslParser::parse($document);

        self::assertSame(['headline'], $parsed->elementIds());
    }

    public function testADocumentThatIsNotUtf8IsRefused(): void
    {
        $violations = self::reject(self::set(self::document(), ['elements', 0, 'text'], "a\xFFb"))->violations;

        self::assertStringContainsString('not valid UTF-8', $violations[0]->message);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        return [
            'canvas' => ['width' => 1080, 'height' => 1350],
            'elements' => [[
                'kind' => 'text', 'id' => 'headline', 'text' => 'Hello', 'font' => 'Inter (Inter Bold)', 'size' => 96,
                'x' => 80, 'y' => 80, 'width' => 900,
                'input' => ['name' => 'Nadpis', 'sampleValue' => 'Hello'],
            ]],
        ];
    }

    /**
     * @param array<array-key, mixed> $document
     * @param list<int|string> $path
     * @return array<array-key, mixed>
     */
    private static function set(array $document, array $path, mixed $value): array
    {
        $key = array_shift($path);
        self::assertNotNull($key);

        if ($path === []) {
            $document[$key] = $value;

            return $document;
        }

        $inner = $document[$key] ?? [];
        self::assertIsArray($inner);
        $document[$key] = self::set($inner, $path, $value);

        return $document;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function reject(array $payload): InvalidDesignDocument
    {
        try {
            DslParser::parse($payload);
        } catch (InvalidDesignDocument $exception) {
            return $exception;
        }

        self::fail('Expected the design document to be rejected, but it parsed.');
    }
}
