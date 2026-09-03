<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Entity;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Value\FontFace;

/**
 * @covers \WBoost\Web\Entity\Font
 */
final class FontTest extends TestCase
{
    public function testNewFacesLandInWeightOrderUprightsBeforeItalics(): void
    {
        $font = $this->font(new FontFace('Bold', 700, 'normal', 'f/bold.ttf'));
        $font->addFontFace(new FontFace('Light', 300, 'normal', 'f/light.ttf'));
        $font->addFontFace(new FontFace('Bold Italic', 700, 'italic', 'f/bold-italic.ttf'));
        $font->addFontFace(new FontFace('Regular', 400, 'normal', 'f/regular.ttf'));
        $font->addFontFace(new FontFace('Light Italic', 300, 'normal', 'f/light-italic.ttf'));

        self::assertSame(
            ['Light', 'Regular', 'Bold', 'Light Italic', 'Bold Italic'],
            array_map(static fn (FontFace $face): string => $face->name, $font->faces),
        );
    }

    public function testADraggedOrderIsKeptAndNewFacesAppend(): void
    {
        $font = $this->font(new FontFace('Bold', 700, 'normal', 'f/bold.ttf'));
        $font->addFontFace(new FontFace('Regular', 400, 'normal', 'f/regular.ttf'));
        // The designer put Bold first on purpose.
        $font->sortFaces(['Bold', 'Regular']);

        $font->addFontFace(new FontFace('Light', 300, 'normal', 'f/light.ttf'));

        self::assertSame(
            ['Bold', 'Regular', 'Light'],
            array_map(static fn (FontFace $face): string => $face->name, $font->faces),
        );
    }

    private function font(FontFace $first): Font
    {
        $project = new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', new \DateTimeImmutable(), true),
            new \DateTimeImmutable(),
            'Project 1',
        );

        return new Font(Uuid::uuid4(), $project, new \DateTimeImmutable(), 'Rubik', $first);
    }
}
