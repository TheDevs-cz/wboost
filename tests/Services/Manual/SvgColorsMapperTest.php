<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Manual;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SvgColorsMapper;

/**
 * @covers \WBoost\Web\Services\SvgColorsMapper
 */
final class SvgColorsMapperTest extends TestCase
{
    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:#005086;}</style>'
        . '<path class="a"/><path fill="#0070B8"/><path fill="#65bde6"/><path fill="#0070B8AA"/></svg>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/svg-mapper-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/logo.svg', self::SVG);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/logo.svg');
        @rmdir($this->dir);
    }

    public function testOpaqueHexTargetsKeepTheLegacyReplacement(): void
    {
        $svg = $this->mapper()->map('logo.svg', ['005086' => 'FFFFFF', '0070B8' => '005086']);

        self::assertStringContainsString('.a{fill:#FFFFFF;}', $svg);
        // Swapped, not chained: 0070B8 → 005086 must not continue to FFFFFF.
        self::assertStringContainsString('<path fill="#005086"/>', $svg);
    }

    public function testRgbaTargetReplacesTheHashedColourWithRgba(): void
    {
        $svg = $this->mapper()->map('logo.svg', [
            '005086' => 'rgba(255,255,255,0.5)',
            '65BDE6' => 'rgba(0,0,0,0.25)',
        ]);

        self::assertStringContainsString('.a{fill:rgba(255,255,255,0.5);}', $svg);
        self::assertStringContainsString('<path fill="rgba(0,0,0,0.25)"/>', $svg);
        self::assertStringNotContainsString('#rgba', $svg);
    }

    public function testRgbaReplacementOnlyMatchesTheWholeHexLiteral(): void
    {
        $svg = $this->mapper()->map('logo.svg', ['0070B8' => 'rgba(255,255,255,0.5)']);

        self::assertStringContainsString('<path fill="rgba(255,255,255,0.5)"/>', $svg);
        // An 8-digit literal that merely STARTS with the key is another colour.
        self::assertStringContainsString('<path fill="#0070B8AA"/>', $svg);
    }

    public function testTargetsThatAreNoColourAreIgnored(): void
    {
        $svg = $this->mapper()->map('logo.svg', [
            '005086' => '"/><script>alert(1)</script>',
            '0070B8' => 'rgba(1,2,3,0.5);}</style><script>',
        ]);

        self::assertSame(self::SVG, $svg);
    }

    private function mapper(): SvgColorsMapper
    {
        return new SvgColorsMapper(new Filesystem(new LocalFilesystemAdapter($this->dir)));
    }
}
