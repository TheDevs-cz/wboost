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
    public function testCanvasFamiliesExpandToAllFacesOfTheirFont(): void
    {
        $canvas = json_encode([
            'objects' => [
                ['type' => 'Textbox', 'fontFamily' => 'Roboto (Roboto Regular)'],
                ['type' => 'image', 'fontFamily' => 'Ignored (Not A Textbox)'],
            ],
        ], JSON_THROW_ON_ERROR);

        $options = ResolveRichTextOptions::computeFonts(
            [$this->font('Roboto', ['Roboto Regular' => [400, 'normal'], 'Roboto Bold' => [700, 'normal']]), $this->font('Lato', ['Lato Regular' => [400, 'normal']])],
            $canvas,
            $this->uploaderHelper(),
        );

        self::assertSame(
            ['Roboto (Roboto Regular)', 'Roboto (Roboto Bold)'],
            array_map(static fn (RichTextFontOption $option): string => $option->family, $options),
        );
        self::assertSame('Roboto', $options[1]->fontName);
        self::assertSame('Roboto Bold', $options[1]->faceName);
        self::assertSame(700, $options[1]->weight);
        self::assertSame('https://assets.test/fonts/p1/roboto-bold.woff2', $options[1]->url);
    }

    public function testFallsBackToAllProjectFontsWhenNoCanvasFamilyMatches(): void
    {
        $canvas = json_encode([
            'objects' => [
                ['type' => 'textbox', 'fontFamily' => 'Times New Roman'],
            ],
        ], JSON_THROW_ON_ERROR);

        $options = ResolveRichTextOptions::computeFonts(
            [$this->font('Roboto', ['Roboto Regular' => [400, 'normal']]), $this->font('Lato', ['Lato Italic' => [400, 'italic']])],
            $canvas,
            $this->uploaderHelper(),
        );

        self::assertSame(
            ['Roboto (Roboto Regular)', 'Lato (Lato Italic)'],
            array_map(static fn (RichTextFontOption $option): string => $option->family, $options),
        );
    }

    public function testEmptyOrBrokenCanvasFallsBackToAllProjectFonts(): void
    {
        $fonts = [$this->font('Roboto', ['Roboto Regular' => [400, 'normal']])];

        self::assertCount(1, ResolveRichTextOptions::computeFonts($fonts, '{}', $this->uploaderHelper()));
        self::assertCount(1, ResolveRichTextOptions::computeFonts($fonts, 'not-json', $this->uploaderHelper()));
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

    private function uploaderHelper(): UploaderHelper
    {
        return new UploaderHelper('https://assets.test');
    }
}
