<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Editor\ResolveEditorFontDefaults;
use WBoost\Web\Value\FontFace;
use WBoost\Web\Value\ManualFontType;
use WBoost\Web\Value\ManualType;

/**
 * @covers \WBoost\Web\Services\Editor\ResolveEditorFontDefaults
 */
final class ResolveEditorFontDefaultsTest extends TestCase
{
    public function testPrimaryManualFontsRegularCutIsTheDefaultAndEnabledFacesArePreset(): void
    {
        $project = $this->project();
        $rubik = $this->font($project, 'Rubik', ['Rubik Light' => 300, 'Rubik Regular' => 400, 'Rubik Bold' => 700, 'Rubik Italic' => 400]);
        $lato = $this->font($project, 'Lato', ['Lato Black' => 900]);
        $manual = $this->manual($project);

        $secondary = new ManualFont(Uuid::uuid4(), $manual, $lato, ManualFontType::Secondary, null, 0, new \DateTimeImmutable());
        $primary = new ManualFont(Uuid::uuid4(), $manual, $rubik, ManualFontType::Primary, null, 1, new \DateTimeImmutable());
        $primary->enableFontFaces(['Rubik Bold', 'Rubik Italic']);
        $manual->fonts->add($secondary);
        $manual->fonts->add($primary);

        $defaults = ResolveEditorFontDefaults::compute([$manual], [$lato, $rubik]);

        // Among the ENABLED faces: upright beats italic even at the same weight.
        self::assertSame('Rubik (Rubik Bold)', $defaults->defaultFamily);
        // Primary faces first, then secondary; a font with no enabled faces
        // contributes all of them.
        self::assertSame(['Rubik (Rubik Bold)', 'Rubik (Rubik Italic)', 'Lato (Lato Black)'], $defaults->manualFaces);
    }

    public function testWithoutManualsTheFirstProjectFontsRegularCutIsTheDefault(): void
    {
        $project = $this->project();
        $lato = $this->font($project, 'Lato', ['Lato Black' => 900, 'Lato Regular' => 400]);

        $defaults = ResolveEditorFontDefaults::compute([], [$lato]);

        self::assertSame('Lato (Lato Regular)', $defaults->defaultFamily);
        self::assertSame([], $defaults->manualFaces);
        self::assertNull(ResolveEditorFontDefaults::compute([], [])->defaultFamily);
    }

    /**
     * @param array<string, int> $faces name => weight
     */
    private function font(Project $project, string $name, array $faces): Font
    {
        $font = null;
        foreach ($faces as $faceName => $weight) {
            $face = new FontFace($faceName, $weight, str_contains($faceName, 'Italic') ? 'italic' : 'normal', 'f/' . $faceName . '.ttf');
            if ($font === null) {
                $font = new Font(Uuid::uuid4(), $project, new \DateTimeImmutable(), $name, $face);
            } else {
                $font->addFontFace($face);
            }
        }
        assert($font instanceof Font);

        return $font;
    }

    private function manual(Project $project): Manual
    {
        return new Manual(Uuid::uuid4(), $project, new \DateTimeImmutable(), ManualType::Brand, 'Manual', null);
    }

    private function project(): Project
    {
        return new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', new \DateTimeImmutable(), true),
            new \DateTimeImmutable(),
            'Project 1',
        );
    }
}
