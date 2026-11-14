<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\AiScan\Template\ScanDesignLimits;

/**
 * The caps on a design that comes from the scanner. The refused cases are the
 * red-team run's own mutations (2026-11-13, `fuzz-design.mjs`): one change to
 * a valid design each — the ones the app then accepted or choked on.
 */
final class ScanDesignLimitsTest extends TestCase
{
    private const string FACE = 'Inter (Inter ExtraBold)';

    /**
     * One mutation of {@see design()} each, the code it must be refused with and where.
     *
     * @return iterable<string, array{\Closure, string, string}>
     */
    public static function beyondTheLimits(): iterable
    {
        $text = static fn (int $i): array => ['kind' => 'text', 'id' => 't' . $i, 'text' => 'T' . $i, 'font' => self::FACE, 'size' => 12, 'x' => 10, 'y' => 10 + ($i % 100) * 12, 'width' => 200, 'input' => ['name' => 'Text ' . $i, 'maxLength' => 10, 'sampleValue' => 'T' . $i]];
        $image = static fn (int $i): array => ['kind' => 'image', 'id' => 'i' . $i, 'asset' => '00000000-0000-4000-8000-0000000a15ca', 'x' => ($i % 20) * 50, 'y' => intdiv($i, 20) * 50, 'width' => 40, 'height' => 40, 'input' => ['name' => 'Obr ' . $i]];

        // text
        yield 'text-1mb' => [static fn (array $d): array => self::at($d, 'headline', ['text' => str_repeat('A', 1_000_000)]), 'document_too_large', ''];
        yield 'text-100k' => [static fn (array $d): array => self::at($d, 'headline', ['text' => str_repeat('A', 100_000)]), 'text_too_long', 'elements[3].text'];
        yield 'text-10k' => [static fn (array $d): array => self::at($d, 'headline', ['text' => str_repeat('A', 10_000)]), 'text_too_long', 'elements[3].text'];
        yield 'sample-10k' => [static fn (array $d): array => self::at($d, 'headline', ['input' => ['name' => 'Nadpis', 'sampleValue' => str_repeat('Ž', 10_000)]]), 'text_too_long', 'elements[3].input.sampleValue'];
        yield 'many texts, each within the limit' => [
            static fn (array $d): array => self::adding($d, array_map(static fn (int $i): array => ['text' => str_repeat('B', 4000)] + $text($i), range(0, 9))),
            'text_too_long',
            'elements',
        ];
        // names
        yield 'input-name-long' => [static fn (array $d): array => self::at($d, 'headline', ['input' => ['name' => str_repeat('N', 5000)]]), 'string_too_long', 'elements[3].input.name'];
        yield 'shape-name-long' => [static fn (array $d): array => self::at($d, 'pruh', ['name' => str_repeat('S', 100_000)]), 'string_too_long', 'elements[1].name'];
        yield 'name with a right-to-left override' => [static fn (array $d): array => self::at($d, 'headline', ['input' => ['name' => "faktura\u{202E}fdp.exe"]]), 'invisible_character', 'elements[3].input.name'];
        yield 'name with a zero-width space' => [static fn (array $d): array => self::at($d, 'pruh', ['name' => "Pruh\u{200B}"]), 'invisible_character', 'elements[1].name'];
        yield 'name with a line break' => [static fn (array $d): array => self::at($d, 'foto', ['input' => ['name' => "Foto\nDruhý řádek"]]), 'invisible_character', 'elements[2].input.name'];
        yield 'font with a byte order mark' => [static fn (array $d): array => self::at($d, 'headline', ['font' => "\u{FEFF}" . self::FACE]), 'invisible_character', 'elements[3].font'];
        // ids
        yield 'id-constructor' => [static fn (array $d): array => self::at($d, 'sub', ['id' => 'constructor']), 'reserved_id', 'elements[4].id'];
        yield 'id-prototype' => [static fn (array $d): array => self::at($d, 'sub', ['id' => 'prototype']), 'reserved_id', 'elements[4].id'];
        yield 'id-proto' => [static fn (array $d): array => self::at($d, 'sub', ['id' => '__proto__']), 'reserved_id', 'elements[4].id'];
        yield 'id-reserved-misto' => [static fn (array $d): array => self::at($d, 'sub', ['id' => 'misto-1']), 'reserved_id', 'elements[4].id'];
        yield 'id-reserved-misto-12' => [static fn (array $d): array => self::at($d, 'foto', ['id' => 'misto-12']), 'reserved_id', 'elements[2].id'];
        // numbers
        yield 'size-1e9' => [static fn (array $d): array => self::at($d, 'headline', ['size' => 1e9]), 'number_out_of_range', 'elements[3].size'];
        yield 'size above the longest edge' => [static fn (array $d): array => self::at($d, 'headline', ['size' => 5001]), 'number_out_of_range', 'elements[3].size'];
        yield 'xy-1e12' => [static fn (array $d): array => self::at($d, 'pruh', ['x' => 1e12, 'y' => -1e12]), 'number_out_of_range', 'elements[1].x'];
        yield 'width-1e9' => [static fn (array $d): array => self::at($d, 'pruh', ['width' => 1e9]), 'number_out_of_range', 'elements[1].width'];
        yield 'lineheight-1e6' => [static fn (array $d): array => self::at($d, 'headline', ['lineHeight' => 1e6]), 'number_out_of_range', 'elements[3].lineHeight'];
        yield 'corner-1e9' => [static fn (array $d): array => self::at($d, 'pruh', ['cornerRadius' => 1e9]), 'number_out_of_range', 'elements[1].cornerRadius'];
        yield 'maxlength-huge' => [static fn (array $d): array => self::at($d, 'headline', ['input' => ['name' => 'Nadpis', 'maxLength' => 2_000_000_000]]), 'number_out_of_range', 'elements[3].input.maxLength'];
        yield 'canvas-20000' => [static fn (array $d): array => ['canvas' => ['width' => 20001, 'height' => 20001]] + $d, 'number_out_of_range', 'canvas.width'];
        yield 'infinite number' => [static fn (array $d): array => self::at($d, 'pruh', ['opacity' => INF]), 'number_out_of_range', 'elements[1].opacity'];
        // structure
        yield 'elements-2000-texts' => [static fn (array $d): array => self::adding($d, array_map($text, range(0, 1999))), 'document_too_large', ''];
        yield 'elements-500-texts' => [static fn (array $d): array => self::adding($d, array_map($text, range(0, 499))), 'too_many_elements', 'elements'];
        yield 'elements-300-images' => [static fn (array $d): array => self::adding($d, array_map($image, range(0, 289))), 'too_many_images', 'elements'];
        yield 'fill-gradient-1000-stops' => [static fn (array $d): array => self::at($d, 'pruh', ['fill' => ['type' => 'linear', 'angle' => 90, 'stops' => array_map(static fn (int $i): array => ['at' => $i / 999, 'color' => '#112233', 'opacity' => 1], range(0, 999))]]), 'list_too_long', 'elements[1].fill.stops'];
        yield 'deep-json' => [
            static function (array $d): array {
                $deep = [];
                $cursor = &$deep;

                for ($i = 0; $i < 40; $i++) {
                    $cursor['a'] = [];
                    $cursor = &$cursor['a'];
                }

                unset($cursor);

                return self::at($d, 'pruh', ['deep' => $deep]);
            },
            'too_deep',
            'elements[1].deep.a.a.a.a.a',
        ];
        yield 'containers-60' => [static fn (array $d): array => self::adding($d, array_map(static fn (int $i): array => ['kind' => 'container', 'id' => 'c' . $i, 'members' => ['headline', 'sub'], 'maxHeight' => 500], range(0, 59))), 'too_many_containers', 'elements'];
    }

    #[DataProvider('beyondTheLimits')]
    public function testADocumentBeyondTheLimitsIsRefusedWithAReason(\Closure $mutate, string $code, string $path): void
    {
        $issues = ScanDesignLimits::violations($mutate(self::design()));

        self::assertNotSame([], $issues, 'the document must be refused');
        self::assertContains($code, array_column($issues, 'code'));

        $issue = $issues[(int) array_search($code, array_column($issues, 'code'), true)];
        self::assertSame($path, $issue['path']);
        self::assertSame('error', $issue['severity']);
        self::assertSame('limits', $issue['stage']);
        // A reason, never the document's own content: short, printable.
        self::assertLessThan(300, strlen($issue['message']));
        self::assertSame(0, preg_match('/[\x{00}-\x{1F}\x{7F}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}]/u', $issue['message'] . $issue['path']));
        self::assertLessThanOrEqual(12, count($issues));
    }

    public function testWhatRealScansProduceIsWithinTheLimits(): void
    {
        self::assertSame([], ScanDesignLimits::violations(self::design()));

        // The largest stored candidate (2026-11): 53 elements, 13 kB, a text of 234 characters — here
        // 195 elements, ~60 kB, 23 000 characters of text, one text of 4 000, on the largest canvas.
        $design = self::design();

        foreach (range(0, 149) as $i) {
            $design['elements'][] = [
                'kind' => 'text', 'id' => 'text-' . $i, 'text' => str_repeat('Lorem ipsum dolor sit amet ', $i === 0 ? 150 : 4) . "\nDruhý řádek\ts tabulátorem",
                'font' => self::FACE, 'size' => 28.5, 'color' => '#1a1a1a', 'lineHeight' => 1.16, 'letterSpacing' => -0.5, 'x' => 80.25, 'y' => 120 + $i * 30, 'width' => 900,
                'input' => ['name' => 'Odstavec ' . $i, 'maxLength' => 2000, 'sampleValue' => 'Ukázka 👩‍💻'],
            ];
        }

        foreach (range(0, 39) as $i) {
            $design['elements'][] = ['kind' => 'image', 'id' => 'foto-' . $i, 'asset' => '00000000-0000-4000-8000-0000000a15ca', 'x' => -200, 'y' => 4800, 'width' => 5000, 'height' => 2500, 'input' => ['name' => 'Fotografie ' . $i]];
        }

        $design['canvas'] = ['width' => 5000, 'height' => 5000];

        self::assertSame([], ScanDesignLimits::violations($design));
    }

    public function testSomethingThatIsNotADocumentIsLeftToTheParser(): void
    {
        self::assertSame([], ScanDesignLimits::violations('just a string'));
        self::assertSame([], ScanDesignLimits::violations(null));
        self::assertSame([], ScanDesignLimits::violations([1, 2, 3]));
        self::assertSame([], ScanDesignLimits::violations(['elements' => ['0' => 'x']]));
    }

    public function testTheWalkStaysCheapOnAHostileDocument(): void
    {
        // 250 kB of ten-character strings: within the size limit, every one a violation.
        $design = self::at(self::design(), 'pruh', ['junk' => array_fill(0, 150, "bad\u{202E}name")]);
        $design = self::at($design, 'headline', ['junk' => array_map(static fn (int $i): array => ['name' => "x\u{200B}" . $i], range(0, 150))]);

        $started = hrtime(true);
        $issues = ScanDesignLimits::violations($design);

        self::assertCount(12, $issues, 'the list of reasons is bounded');
        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9);
    }

    /**
     * The red-team run's base design (`rt-lib.mjs` `baseDesign`).
     *
     * @return array{canvas: array<string, int>, elements: list<array<string, mixed>>}
     */
    private static function design(): array
    {
        return ['canvas' => ['width' => 1080, 'height' => 1350], 'elements' => [
            ['kind' => 'background', 'id' => 'bg', 'fill' => '#f4f1ea'],
            ['kind' => 'shape', 'id' => 'pruh', 'shape' => 'rectangle', 'fill' => '#c8102e', 'x' => 0, 'y' => 1100, 'width' => 1080, 'height' => 250],
            ['kind' => 'image', 'id' => 'foto', 'asset' => '00000000-0000-4000-8000-0000000a15ca', 'x' => 100, 'y' => 400, 'width' => 400, 'height' => 300, 'input' => ['name' => 'Fotografie']],
            ['kind' => 'text', 'id' => 'headline', 'text' => "Den otevřených\ndveří", 'font' => self::FACE, 'size' => 64, 'color' => '#c8102e', 'x' => 80, 'y' => 120, 'width' => 600, 'input' => ['name' => 'Nadpis', 'maxLength' => 40, 'sampleValue' => "Den otevřených\ndveří"]],
            ['kind' => 'text', 'id' => 'sub', 'text' => 'Sobota 14. června', 'font' => self::FACE, 'size' => 36, 'x' => 80, 'y' => 240, 'width' => 600, 'input' => ['name' => 'Datum', 'maxLength' => 30, 'sampleValue' => 'Sobota 14. června']],
        ]];
    }

    /**
     * The design with the element of that id changed.
     *
     * @param array<array-key, mixed> $design
     * @param array<string, mixed> $change
     * @return array<array-key, mixed>
     */
    private static function at(array $design, string $id, array $change): array
    {
        $elements = $design['elements'] ?? null;
        self::assertIsArray($elements);

        foreach ($elements as $index => $element) {
            self::assertIsArray($element);

            if (($element['id'] ?? null) === $id) {
                $elements[$index] = $change + $element;
            }
        }

        $design['elements'] = $elements;

        return $design;
    }

    /**
     * The design with more elements at its end.
     *
     * @param array<array-key, mixed> $design
     * @param array<array-key, mixed> $more
     * @return array<array-key, mixed>
     */
    private static function adding(array $design, array $more): array
    {
        $elements = $design['elements'] ?? null;
        self::assertIsArray($elements);
        $design['elements'] = [...array_values($elements), ...array_values($more)];

        return $design;
    }
}
