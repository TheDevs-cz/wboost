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
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\SvgImage;

/**
 * "Zobrazovat variantu na samostatné stránce": a promoted variant renders a
 * page of its own and must therefore disappear from the shared pages.
 *
 * @covers \WBoost\Web\Entity\Manual
 * @covers \WBoost\Web\Value\SvgImage
 */
final class ManualLogoOwnPageTest extends TestCase
{
    public function testByDefaultEveryUploadedVariantIsOnTheSharedPages(): void
    {
        $manual = $this->manual();

        self::assertSame([], $manual->logoOwnPageVariants());
        self::assertTrue($manual->logoOnSharedPage('horizontal'));
        self::assertTrue($manual->logoOnSharedPage('vertical'));
        self::assertTrue($manual->logoOnSharedPage('symbol'));
    }

    public function testAVariantThatWasNeverUploadedIsOnNoPageAtAll(): void
    {
        $manual = $this->manual();

        self::assertFalse($manual->logoOnSharedPage('horizontalWithClaim'));
        self::assertFalse($manual->logoOnSharedPage('verticalWithClaim'));
    }

    public function testAPromotedVariantLeavesTheSharedPages(): void
    {
        $manual = $this->manual();
        $manual->logo->vertical?->updateOwnPage(true);

        self::assertSame([LogoTypeVariant::Vertical], $manual->logoOwnPageVariants());
        self::assertFalse($manual->logoOnSharedPage('vertical'));
        // Its page-mates are unaffected.
        self::assertTrue($manual->logoOnSharedPage('horizontal'));
        self::assertTrue($manual->logoOnSharedPage('symbol'));
    }

    public function testPromotionIsReversible(): void
    {
        $manual = $this->manual();
        $manual->logo->vertical?->updateOwnPage(true);
        $manual->logo->vertical?->updateOwnPage(false);

        self::assertSame([], $manual->logoOwnPageVariants());
        self::assertTrue($manual->logoOnSharedPage('vertical'));
    }

    public function testVariantsAreReportedInTheirCanonicalOrder(): void
    {
        $manual = $this->manual();
        $manual->logo->symbol?->updateOwnPage(true);
        $manual->logo->horizontal?->updateOwnPage(true);

        self::assertSame(
            [LogoTypeVariant::Horizontal, LogoTypeVariant::Symbol],
            $manual->logoOwnPageVariants(),
        );
    }

    /**
     * The flag lives in the logo JSONB document, so it has to survive the
     * round-trip — and a row written before it existed must read as "off".
     */
    public function testTheFlagRoundTripsAndDefaultsToOffOnOlderRows(): void
    {
        $image = new SvgImage('m/horizontal.svg', [], [], null, null, null, true);

        self::assertTrue($image->ownPage);
        self::assertTrue(SvgImage::fromArray($image->toArray())->ownPage);

        $legacy = SvgImage::fromArray(['filePath' => 'm/old.svg', 'detectedColors' => []]);
        self::assertFalse($legacy->ownPage);
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

        $manual = new Manual(Uuid::uuid4(), $project, $date, ManualType::Logo, 'Manual 1', null);

        $manual->editLogo(new Logo(
            horizontal: new SvgImage('m/horizontal.svg', [], []),
            vertical: new SvgImage('m/vertical.svg', [], []),
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: new SvgImage('m/symbol.svg', [], []),
        ));

        return $manual;
    }
}
