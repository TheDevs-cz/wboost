<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Services\Template\FillValueGuard;

/**
 * @covers \WBoost\Web\Services\Template\FillValueGuard
 */
final class FillValueGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}>
     */
    public static function unstorable(): iterable
    {
        yield 'a value' => [['inputs' => ['a' => "x\0"]], 'inputs.a '];
        yield 'a nested value' => [['inputs' => ['a' => ['runs' => [['text' => 'ok'], ['text' => "\0"]]]]], 'inputs.a.runs[1].text '];
        yield 'a key' => [['images' => ["a\0b" => 'x']], 'images.a b '];
        yield 'a row value' => [['rows' => ['r' => [['m' => "\0"]]]], 'rows.r[0].m '];
        yield 'not UTF-8' => [['inputs' => ['a' => "\xff"]], 'inputs.a '];
        yield 'an encoded surrogate' => [['inputs' => ['a' => "\xed\xa0\x80"]], 'inputs.a '];
    }

    /**
     * @param array<string, mixed> $maps
     * @param non-empty-string $path
     */
    #[DataProvider('unstorable')]
    public function testRefusesNamingThePath(array $maps, string $path): void
    {
        try {
            FillValueGuard::assertStorable($maps);
            self::fail('Not refused.');
        } catch (InvalidExportValue $refused) {
            self::assertSame('invalid_value', $refused->errorCode);
            self::assertStringStartsWith($path, $refused->getMessage());
            self::assertTrue(mb_check_encoding($refused->getMessage(), 'UTF-8'));
            self::assertStringNotContainsString("\0", $refused->getMessage());
        }
    }

    public function testEverythingElsePasses(): void
    {
        $this->expectNotToPerformAssertions();

        FillValueGuard::assertStorable([
            'inputs' => ['a' => "tab\t line\n vt\u{0B} nel\u{85} zwj\u{200D} 🙂", 'b' => ['hide' => true, 'value' => null]],
            'images' => ['c' => ['imageId' => 'x', 'scale' => 1.5]],
            'rows' => null,
            'shapeColors' => [],
        ]);
    }

    public function testCleanDropsTheNulAndScrubsBrokenBytes(): void
    {
        self::assertSame(
            ['ab' => ['x', 'a?b', 3, null, true, "v\u{0B}t"], 7 => 'ok'],
            FillValueGuard::clean(["a\0b" => ["\0x", "a\xffb", 3, null, true, "v\u{0B}t"], 7 => 'ok']),
        );
    }
}
