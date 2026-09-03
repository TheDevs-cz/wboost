<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FontFace;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\RichTextFontOption;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions
 */
final class ResolveRichTextOptionsTest extends TestCase
{
    private const string RICH_ID = '11111111-1111-4111-8111-111111111111';
    private const string PLAIN_ID = '22222222-2222-4222-8222-222222222222';
    private const string LOCKED_ID = '33333333-3333-4333-8333-333333333333';

    public function testRichInputOffersEveryFaceOfItsDesignedFamily(): void
    {
        $options = ResolveRichTextOptions::computeInputFonts(
            $this->faces(),
            'Roboto (Roboto Regular)',
            $this->input(self::RICH_ID, richText: true),
        );

        self::assertSame(['Roboto (Roboto Regular)', 'Roboto (Roboto Bold)'], self::families($options));
        self::assertSame('Roboto', $options[1]->fontName);
        self::assertSame('Roboto Bold', $options[1]->faceName);
        self::assertSame(700, $options[1]->weight);
        self::assertSame('https://assets.test/fonts/p1/roboto-bold.woff2', $options[1]->url);
    }

    public function testPlainInputOffersOnlyItsDesignedFaceUntilTheDesignerOpensItUp(): void
    {
        $faces = $this->faces();

        self::assertSame(
            ['Roboto (Roboto Bold)'],
            self::families(ResolveRichTextOptions::computeInputFonts($faces, 'Roboto (Roboto Bold)', $this->input(self::PLAIN_ID))),
        );

        // Designed face first, then the picks in PROJECT order — not the
        // order the admin ticked them in.
        self::assertSame(
            ['Roboto (Roboto Bold)', 'Roboto (Roboto Regular)', 'Lato (Lato Italic)'],
            self::families(ResolveRichTextOptions::computeInputFonts(
                $faces,
                'Roboto (Roboto Bold)',
                $this->input(self::PLAIN_ID, allowedFonts: ['Lato (Lato Italic)', 'Roboto (Roboto Regular)']),
            )),
        );
    }

    public function testStalePicksAndTheDesignedFaceItselfAreNotDuplicated(): void
    {
        $options = ResolveRichTextOptions::computeInputFonts(
            $this->faces(),
            'Roboto (Roboto Bold)',
            $this->input(self::PLAIN_ID, allowedFonts: ['Roboto (Roboto Bold)', 'Gone (Gone Regular)', 'Lato (Lato Italic)']),
        );

        self::assertSame(['Roboto (Roboto Bold)', 'Lato (Lato Italic)'], self::families($options));
    }

    public function testRichInputWithNoResolvableFontFallsBackToAllProjectFontsButAPlainOneOffersNothing(): void
    {
        $faces = $this->faces();

        self::assertSame(
            ['Roboto (Roboto Regular)', 'Roboto (Roboto Bold)', 'Lato (Lato Italic)'],
            self::families(ResolveRichTextOptions::computeInputFonts($faces, 'Times New Roman', $this->input(self::RICH_ID, richText: true))),
        );
        self::assertSame(
            [],
            self::families(ResolveRichTextOptions::computeInputFonts($faces, 'Times New Roman', $this->input(self::PLAIN_ID))),
        );
        // A pick still applies to a rich input whose designed font is foreign.
        self::assertSame(
            ['Lato (Lato Italic)'],
            self::families(ResolveRichTextOptions::computeInputFonts($faces, 'Times New Roman', $this->input(self::RICH_ID, richText: true, allowedFonts: ['Lato (Lato Italic)']))),
        );
    }

    public function testBareFontNameOnTheCanvasOffersTheWholeFont(): void
    {
        self::assertSame(
            ['Roboto (Roboto Regular)', 'Roboto (Roboto Bold)'],
            self::families(ResolveRichTextOptions::computeInputFonts($this->faces(), 'Roboto', $this->input(self::PLAIN_ID))),
        );
    }

    public function testVariantOptionsAreKeyedPerInputAndTheUnionCoversRichInputsOnly(): void
    {
        $options = ResolveRichTextOptions::compute(
            $this->faces(),
            [
                $this->input(self::RICH_ID, richText: true),
                $this->input(self::PLAIN_ID, allowedFonts: ['Lato (Lato Italic)']),
                $this->input(self::LOCKED_ID, locked: true),
            ],
            [
                self::RICH_ID => 'Roboto (Roboto Bold)',
                self::PLAIN_ID => 'Roboto (Roboto Regular)',
                self::LOCKED_ID => 'Lato (Lato Italic)',
            ],
            ['#c8102e'],
        );

        self::assertSame(['Roboto (Roboto Regular)', 'Roboto (Roboto Bold)'], $options->allowedFamilies());
        self::assertSame(['Roboto (Roboto Regular)', 'Roboto (Roboto Bold)'], $options->allowedFamiliesFor(self::RICH_ID));
        self::assertSame(['Roboto (Roboto Regular)', 'Lato (Lato Italic)'], $options->allowedFamiliesFor(self::PLAIN_ID));
        // Locked inputs get no entry — and an unknown id falls back to the
        // union, never to "anything goes".
        self::assertArrayNotHasKey(self::LOCKED_ID, $options->fontsByInput);
        self::assertSame($options->allowedFamilies(), $options->allowedFamiliesFor(self::LOCKED_ID));
        self::assertSame(['#c8102e'], $options->colors);

        self::assertSame(
            [['name' => 'Roboto', 'faces' => [
                ['family' => 'Roboto (Roboto Regular)', 'faceName' => 'Roboto Regular'],
                ['family' => 'Roboto (Roboto Bold)', 'faceName' => 'Roboto Bold'],
            ]]],
            $options->toToolbarArray()['fontGroups'],
        );
    }

    public function testColorsAreOrderedPrimarySecondaryUntypedAndDeduped(): void
    {
        $manualA = $this->manual('Manual A');
        $manualA->editColors(
            detectedColors: [],
            customColors: [
                new ManualColor(new Color('#C8102E'), ManualColorType::Primary, null, null),
                new ManualColor(new Color('#00FF00'), ManualColorType::Secondary, null, null),
                // Untyped custom color — lands in the last swatch bucket.
                new ManualColor(new Color('#ABCDEF'), null, null, null),
            ],
        );

        $manualB = $this->manual('Manual B');
        $manualB->editColors(
            detectedColors: [],
            customColors: [
                new ManualColor(new Color('#123456'), ManualColorType::Primary, null, null),
                // Duplicate of manual A's primary, different case — must dedup.
                new ManualColor(new Color('#c8102e'), ManualColorType::Secondary, null, null),
            ],
        );

        $colors = ResolveRichTextOptions::computeColors([$manualA, $manualB]);

        self::assertSame(['#c8102e', '#123456', '#00ff00', '#abcdef'], $colors);
    }

    public function testNoManualsMeansNoSwatches(): void
    {
        self::assertSame([], ResolveRichTextOptions::computeColors([]));
    }

    /**
     * @param list<RichTextFontOption> $options
     * @return list<string>
     */
    private static function families(array $options): array
    {
        return array_map(static fn (RichTextFontOption $option): string => $option->family, $options);
    }

    /**
     * @return list<RichTextFontOption>
     */
    private function faces(): array
    {
        return ResolveRichTextOptions::projectFaces(
            [
                $this->font('Roboto', ['Roboto Regular' => [400, 'normal'], 'Roboto Bold' => [700, 'normal']]),
                $this->font('Lato', ['Lato Italic' => [400, 'italic']]),
            ],
            new UploaderHelper('https://assets.test'),
        );
    }

    /**
     * @param list<string> $allowedFonts
     */
    private function input(string $id, bool $richText = false, bool $locked = false, array $allowedFonts = []): EditorTextInput
    {
        return new EditorTextInput($id, 'field', null, $locked, false, null, false, richText: $richText, allowedFonts: $allowedFonts);
    }

    /**
     * @param array<string, array{int, string}> $faces faceName => [weight, style]
     */
    private function font(string $name, array $faces): Font
    {
        $project = new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', new \DateTimeImmutable(), true),
            new \DateTimeImmutable(),
            'Project 1',
        );

        $font = null;

        foreach ($faces as $faceName => [$weight, $style]) {
            $filePath = sprintf('fonts/p1/%s.woff2', str_replace(' ', '-', strtolower($faceName)));
            $face = new FontFace($faceName, $weight, $style, $filePath);

            if ($font === null) {
                $font = new Font(Uuid::uuid4(), $project, new \DateTimeImmutable(), $name, $face);
            } else {
                $font->addFontFace($face);
            }
        }

        assert($font instanceof Font);

        return $font;
    }

    private function manual(string $name): Manual
    {
        $project = new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', new \DateTimeImmutable(), true),
            new \DateTimeImmutable(),
            'Project 1',
        );

        return new Manual(
            Uuid::uuid4(),
            $project,
            new \DateTimeImmutable(),
            ManualType::Brand,
            $name,
            null,
        );
    }
}
