<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Http\RequestBodyLimit;

final class RequestBodyLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function iniSizes(): iterable
    {
        yield 'plain bytes' => ['1048576', 1048576];
        yield 'kilobytes' => ['8K', 8192];
        yield 'megabytes' => ['256M', 268435456];
        yield 'lower-case suffix' => ['50m', 52428800];
        yield 'gigabytes' => ['2G', 2147483648];
        yield 'surrounding whitespace' => [' 256M ', 268435456];
        yield 'zero = unlimited' => ['0', 0];
        yield 'empty = unlimited' => ['', 0];
        yield 'negative = unlimited' => ['-1', 0];
        yield 'garbage = unlimited' => ['lots', 0];
    }

    #[DataProvider('iniSizes')]
    public function testParsesThePhpIniShorthand(string $value, int $bytes): void
    {
        self::assertSame($bytes, RequestBodyLimit::parseIniSize($value));
    }

    /**
     * The client-side guard reads the constant, the listener reads the live
     * ini value — they must name the same limit.
     */
    public function testTheConstantMirrorsTheShippedIniFile(): void
    {
        $configured = parse_ini_file(dirname(__DIR__, 3) . '/.docker/php/wboost.ini');
        self::assertIsArray($configured);
        self::assertIsString($configured['post_max_size'] ?? null);

        self::assertSame(RequestBodyLimit::POST_MAX_SIZE_BYTES, RequestBodyLimit::parseIniSize($configured['post_max_size']));
        self::assertSame(RequestBodyLimit::POST_MAX_SIZE_BYTES, RequestBodyLimit::postMaxSizeBytes(), 'The running PHP does not load .docker/php/wboost.ini.');
    }

    /**
     * The mockup page form's worst case: 6 images x 10 MB + 6 per-image
     * downloads x 20 MB + a 20 MB page download, all decimal.
     */
    public function testTheMockupPageWorstCaseFitsWithHeadroom(): void
    {
        $worstCase = 6 * 10_000_000 + 6 * 20_000_000 + 20_000_000;

        self::assertLessThan(RequestBodyLimit::POST_MAX_SIZE_BYTES - 1024 * 1024, $worstCase);
    }

    public function testTheMessageNamesTheLimitInBinaryMegabytes(): void
    {
        self::assertSame(
            'Nahrávaná data jsou příliš velká (max. 256 MB najednou). Nahrajte prosím soubory po částech.',
            RequestBodyLimit::tooLargeMessage(268435456),
        );
    }
}
