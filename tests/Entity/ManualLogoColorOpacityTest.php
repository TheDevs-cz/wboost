<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\SvgImage;

/**
 * "Mapování barev loga" with Krytí: the hex target stays hex for the form,
 * the rendering mapping turns a translucent target into rgba().
 *
 * @covers \WBoost\Web\Entity\Manual
 * @covers \WBoost\Web\Value\SvgImage
 */
final class ManualLogoColorOpacityTest extends TestCase
{
    public function testWithoutOpacityTheRenderMappingIsPlainHex(): void
    {
        $manual = $this->manual();
        $manual->updateColorMapping(LogoTypeVariant::Horizontal, LogoColorVariant::OneColor, '65BDE6', [
            '005086' => 'ffffff',
        ]);

        $mapping = $manual->logoColorMapping('horizontal', 'oneColor');

        self::assertSame('FFFFFF', $mapping['005086']);
        self::assertSame([], $manual->logoColorOpacities('horizontal', 'oneColor'));
    }

    public function testATranslucentTargetRendersAsRgbaButTheFormKeepsHex(): void
    {
        $manual = $this->manual();
        $manual->updateColorMapping(
            LogoTypeVariant::Horizontal,
            LogoColorVariant::OneColor,
            '65BDE6',
            ['005086' => 'FFFFFF', '0070B8' => 'FFFFFF'],
            ['005086' => 50, '0070B8' => 100],
        );

        $render = $manual->logoColorMapping('horizontal', 'oneColor');
        self::assertSame('rgba(255,255,255,0.5)', $render['005086']);
        self::assertSame('FFFFFF', $render['0070B8']);

        self::assertSame('FFFFFF', $manual->logoColorTargets('horizontal', 'oneColor')['005086']);
        self::assertSame(['005086' => 50], $manual->logoColorOpacities('horizontal', 'oneColor'));

        // Other colour variants are untouched.
        self::assertSame([], $manual->logoColorOpacities('horizontal', 'darkBackground'));
    }

    public function testTheOpacityPersistsThroughTheLogoDocument(): void
    {
        $manual = $this->manual();
        $manual->updateColorMapping(
            LogoTypeVariant::Horizontal,
            LogoColorVariant::OneColor,
            '65BDE6',
            ['005086' => 'FFFFFF'],
            ['005086' => 30],
        );

        $stored = $manual->logo->horizontal?->toArray();
        self::assertNotNull($stored);
        self::assertSame(['005086' => 30], $stored['colorsMapping']['oneColor']['opacities']);

        $restored = SvgImage::fromArray($stored);
        self::assertSame(30, $restored->colorsMapping['oneColor']->opacity('005086'));
    }

    private function manual(): Manual
    {
        $date = new DateTimeImmutable();

        $project = new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', $date, true),
            $date,
            'Project 1',
        );

        $manual = new Manual(Uuid::uuid4(), $project, $date, ManualType::Logo, 'Manual 1');

        $manual->editLogo(new Logo(
            horizontal: new SvgImage('m/horizontal.svg', ['#005086', '#0070B8'], []),
            vertical: null,
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: null,
        ));

        return $manual;
    }
}
