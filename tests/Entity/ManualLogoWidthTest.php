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
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\SvgImage;

/**
 * The width cascade every logo card in a manual resolves through.
 *
 * @covers \WBoost\Web\Entity\Manual
 */
final class ManualLogoWidthTest extends TestCase
{
    public function testWithoutAnyOverrideThereIsNoWidth(): void
    {
        $manual = $this->manual(horizontalWidth: null);

        self::assertNull($manual->logoDisplayWidth('basic_logos.horizontal.base', 'horizontal'));
    }

    public function testTheVariantWidthReachesEveryCardOfThatVariant(): void
    {
        $manual = $this->manual(horizontalWidth: 60);

        foreach ([
            'basic_logos.horizontal.base',
            'horizontal_backgrounds.horizontal.darkBackground',
            'horizontal_monochrome.horizontal.blackBackground',
            'protection_zone.horizontal.base',
            'minimum_dimensions.horizontal.base',
        ] as $slot) {
            self::assertSame(60, $manual->logoDisplayWidth($slot, 'horizontal'), $slot);
        }

        // …and only that variant's cards.
        self::assertNull($manual->logoDisplayWidth('basic_logos.vertical.base', 'vertical'));
    }

    public function testACardsOwnWidthWinsOverItsVariant(): void
    {
        $manual = $this->manual(horizontalWidth: 60);
        $manual->editLogoSlotWidth('protection_zone.horizontal.base', 90);

        self::assertSame(90, $manual->logoDisplayWidth('protection_zone.horizontal.base', 'horizontal'));
        // Siblings of the same variant are untouched.
        self::assertSame(60, $manual->logoDisplayWidth('basic_logos.horizontal.base', 'horizontal'));
    }

    public function testACardsOwnWidthAppliesEvenWhenTheVariantHasNone(): void
    {
        $manual = $this->manual(horizontalWidth: null);
        $manual->editLogoSlotWidth('symbol.symbol.base', 25);

        self::assertSame(25, $manual->logoDisplayWidth('symbol.symbol.base', 'symbol'));
        self::assertNull($manual->logoDisplayWidth('basic_logos.symbol.base', 'symbol'));
    }

    public function testClearingACardsWidthHandsItBackToTheVariant(): void
    {
        $manual = $this->manual(horizontalWidth: 60);
        $manual->editLogoSlotWidth('basic_logos.horizontal.base', 90);

        foreach ([null, 0, -5] as $cleared) {
            $manual->editLogoSlotWidth('basic_logos.horizontal.base', 90);
            $manual->editLogoSlotWidth('basic_logos.horizontal.base', $cleared);

            self::assertSame([], $manual->logoSlotWidths, var_export($cleared, true));
            self::assertSame(60, $manual->logoDisplayWidth('basic_logos.horizontal.base', 'horizontal'));
        }
    }

    public function testAnOversizedWidthIsCappedAtTheFrame(): void
    {
        $manual = $this->manual(horizontalWidth: null);
        $manual->editLogoSlotWidth('basic_logos.horizontal.base', 250);

        self::assertSame(100, $manual->logoSlotWidth('basic_logos.horizontal.base'));
    }

    /**
     * A slot that names a logo the manual does not have resolves to no width
     * rather than blowing up — the template only asks for cards it renders,
     * but a stale stored slot must stay harmless.
     */
    public function testAnUnknownCardResolvesToNoWidth(): void
    {
        $manual = $this->manual(horizontalWidth: 60);

        self::assertNull($manual->logoDisplayWidth('symbol.symbol.base', 'symbol'));
    }

    private function manual(null|int $horizontalWidth): Manual
    {
        $date = new DateTimeImmutable();

        $project = new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', $date, true),
            $date,
            'Project 1',
        );

        $manual = new Manual(Uuid::uuid4(), $project, $date, ManualType::Logo, 'Manual 1', null);

        $manual->editLogo(new Logo(
            horizontal: new SvgImage('m/horizontal.svg', [], [], null, null, $horizontalWidth),
            vertical: new SvgImage('m/vertical.svg', [], [], null, null, null),
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: null,
        ));

        return $manual;
    }
}
