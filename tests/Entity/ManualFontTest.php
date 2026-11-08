<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Value\FontFace;
use WBoost\Web\Value\ManualFontType;
use WBoost\Web\Value\ManualType;

/**
 * @covers \WBoost\Web\Entity\ManualFont
 */
final class ManualFontTest extends TestCase
{
    /**
     * F11 regression: `edit()` compared the font's id with the ManualFont's
     * OWN id (always different), so every save of the edit form cleared the
     * cuts the manual shows.
     */
    public function testEditingRoleAndColourKeepsTheEnabledCuts(): void
    {
        $project = $this->project();
        $font = $this->font($project, 'Rubik');
        $manualFont = $this->manualFont($project, $font);
        $manualFont->enableFontFaces(['Regular', 'Bold']);

        $manualFont->edit($font, ManualFontType::Secondary, '1D2A5C');

        self::assertSame(['Regular', 'Bold'], $manualFont->fontFaces);
        self::assertSame(ManualFontType::Secondary, $manualFont->type);
        self::assertSame('1D2A5C', $manualFont->color);
    }

    /**
     * Switching the font enables every cut of the NEW font (it used to clear
     * them, and a manual font without cuts is not shown in the manual).
     */
    public function testSwitchingToAnotherFontEnablesAllItsCuts(): void
    {
        $project = $this->project();
        $manualFont = $this->manualFont($project, $this->font($project, 'Rubik'));
        $manualFont->enableFontFaces(['Regular', 'Bold']);

        $other = $this->font($project, 'Inter');
        $manualFont->edit($other, ManualFontType::Primary, null);

        self::assertSame(['Regular'], $manualFont->fontFaces);
        self::assertSame($other, $manualFont->font);
    }

    private function project(): Project
    {
        return new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', new DateTimeImmutable(), true),
            new DateTimeImmutable(),
            'Project 1',
        );
    }

    private function font(Project $project, string $name): Font
    {
        return new Font(Uuid::uuid4(), $project, new DateTimeImmutable(), $name, new FontFace('Regular', 400, 'normal', 'f/regular.ttf'));
    }

    private function manualFont(Project $project, Font $font): ManualFont
    {
        $manual = new Manual(Uuid::uuid4(), $project, new DateTimeImmutable(), ManualType::Brand, 'Manual 1', null);

        return new ManualFont(Uuid::uuid4(), $manual, $font, ManualFontType::Primary, null, 0, new DateTimeImmutable());
    }
}
