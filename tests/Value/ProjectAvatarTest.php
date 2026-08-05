<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\ProjectAvatar;

/**
 * @covers \WBoost\Web\Value\ProjectAvatar
 */
final class ProjectAvatarTest extends TestCase
{
    public function testInitialsFromTwoWords(): void
    {
        self::assertSame('AB', ProjectAvatar::initials('Alpha Beta Gamma'));
    }

    public function testInitialsFromSingleWord(): void
    {
        self::assertSame('C', ProjectAvatar::initials('Corrency'));
    }

    public function testInitialsHandleDiacritics(): void
    {
        self::assertSame('ŠA', ProjectAvatar::initials('škoda auto'));
    }

    public function testInitialsFromBlankName(): void
    {
        self::assertSame('?', ProjectAvatar::initials('   '));
    }

    public function testPaletteColorIsDeterministic(): void
    {
        $seed = '018f6f21-1b3a-7c1e-9d0a-111111111111';

        self::assertSame(ProjectAvatar::paletteColor($seed), ProjectAvatar::paletteColor($seed));
    }

    public function testTextColorIsWhiteOnDarkBackground(): void
    {
        self::assertSame('ffffff', ProjectAvatar::textColorFor('c92a2a'));
    }

    public function testTextColorIsDarkOnLightBackground(): void
    {
        self::assertSame('313a46', ProjectAvatar::textColorFor('ffe066'));
    }

    public function testTextColorExpandsShortHex(): void
    {
        self::assertSame('313a46', ProjectAvatar::textColorFor('#fff'));
        self::assertSame('ffffff', ProjectAvatar::textColorFor('#000'));
    }

    public function testBuildPrefersBrandColorOverPalette(): void
    {
        $avatar = ProjectAvatar::build('seed', 'Alpha Beta', null, '1971c2');

        self::assertSame('1971c2', $avatar->backgroundHex);
        self::assertSame('ffffff', $avatar->textHex);
        self::assertSame('AB', $avatar->initials);
        self::assertNull($avatar->logoPath);
    }

    public function testBuildFallsBackToPaletteColor(): void
    {
        $avatar = ProjectAvatar::build('seed', 'Alpha', null, null);

        self::assertSame(ProjectAvatar::paletteColor('seed'), $avatar->backgroundHex);
    }
}
