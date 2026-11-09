<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\ContainerRepeat;
use WBoost\Web\Value\EditorTextInput;

/**
 * Legacy slot ids of a MERGED row (container-rows P6, "Sloučit do
 * opakovaného řádku"): `repeat.slots[k−1]` maps template-tree ids to the ids
 * row k+1 had before the merge, and the expansion gives slot k those ids
 * instead of the derived uuid5 — so every stored value of the old rows lands
 * in its row. Plus the row-count inference for fills that carry no count.
 *
 * Synthetic design: PARENT (gap 10) holds the repeatable ROW (one text A,
 * top 100, h 40) and a footer FOOT (top 200).
 *
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Value\ContainerRepeat
 */
final class RepeatableRowsLegacySlotsTest extends TestCase
{
    private const string HEAD = '01900000-0000-7000-8000-0000000000a1';
    private const string A = '01900000-0000-7000-8000-0000000000a2';
    private const string FOOT = '01900000-0000-7000-8000-0000000000a3';
    private const string OLD_A2 = '01900000-0000-7000-8000-0000000000b2';
    private const string OLD_A3 = '01900000-0000-7000-8000-0000000000b3';
    private const string OLD_ROW2 = 'old-row-2';
    private const string ROW = 'row';
    private const string PARENT = 'parent';

    public function testMergedSlotsTakeTheirLegacyIdsAndTheRestStayDerived(): void
    {
        $expanded = $this->expand(['max' => 4, 'default' => 3, 'slots' => [
            [self::A => self::OLD_A2, self::ROW => self::OLD_ROW2],
            [self::A => self::OLD_A3],
        ]]);

        // Objects: slot 1 and 2 carry the old ids, slot 3 (never merged) a derived one.
        $ids = $this->objectIds($expanded['canvas']);
        self::assertSame([self::HEAD, self::A, self::OLD_A2, self::OLD_A3, RepeatableRows::slotId(self::ROW, self::A, 3), self::FOOT], $ids);

        // Inputs are rebuilt in textbox order with the legacy ids — the stored
        // values of "Střelec 2" / "Střelec 3" bind to rows 2 / 3.
        self::assertSame(
            [self::HEAD, self::A, self::OLD_A2, self::OLD_A3, RepeatableRows::slotId(self::ROW, self::A, 3), self::FOOT],
            array_map(static fn (EditorTextInput $input): string => $input->inputId, $expanded['inputs']),
        );

        // The slot containers: slot 1 keeps the old row container id (its
        // container hide / document key), slot 2 had none mapped → derived.
        $row = CanvasContainer::findById(CanvasContainer::collectionFromCanvas($expanded['canvas']), self::ROW);
        self::assertNotNull($row);
        self::assertSame([self::ROW, self::OLD_ROW2, RepeatableRows::slotId(self::ROW, self::ROW, 2), RepeatableRows::slotId(self::ROW, self::ROW, 3)], $row->repeatSlotIds);
        $slot1 = CanvasContainer::findById(CanvasContainer::collectionFromCanvas($expanded['canvas']), self::OLD_ROW2);
        self::assertNotNull($slot1);
        self::assertSame([self::OLD_A2], $slot1->memberInputIds);
        self::assertSame(self::ROW, $slot1->repeatSlotOf);
    }

    public function testALegacyIdAlreadyUsedInTheDesignFallsBackToTheDerivedId(): void
    {
        // A designer re-used "FOOT" as a legacy id (or added an element that
        // took one back): the merged slot must never duplicate it.
        $expanded = $this->expand(['max' => 3, 'default' => 1, 'slots' => [
            [self::A => self::FOOT],
            [self::A => self::OLD_A3, 'not-in-the-row' => 'whatever'],
        ]]);

        $ids = $this->objectIds($expanded['canvas']);
        self::assertSame([self::HEAD, self::A, RepeatableRows::slotId(self::ROW, self::A, 1), self::OLD_A3, self::FOOT], $ids);
        self::assertCount(count($ids), array_unique($ids), 'no id twice');
        self::assertNotContains('whatever', $ids, 'only ids of the row tree are mapped');
    }

    public function testSlotsBeyondMaxAreIgnoredAndTwoSlotsNeverClaimOneId(): void
    {
        $expanded = $this->expand(['max' => 2, 'default' => 1, 'slots' => [
            [self::A => self::OLD_A2],
            [self::A => self::OLD_A3], // slot 2 is not materialised at max 2
        ]]);
        self::assertSame([self::HEAD, self::A, self::OLD_A2, self::FOOT], $this->objectIds($expanded['canvas']));

        $twice = $this->expand(['max' => 3, 'default' => 1, 'slots' => [
            [self::A => self::OLD_A2],
            [self::A => self::OLD_A2],
        ]]);
        self::assertSame([self::HEAD, self::A, self::OLD_A2, RepeatableRows::slotId(self::ROW, self::A, 2), self::FOOT], $this->objectIds($twice['canvas']));
    }

    public function testCountsAreInferredFromTheAddressedSlotsOnly(): void
    {
        $expanded = $this->expand(['max' => 5, 'default' => 1, 'slots' => [
            [self::A => self::OLD_A2, self::ROW => self::OLD_ROW2],
            [self::A => self::OLD_A3],
        ]]);
        $containers = CanvasContainer::collectionFromCanvas($expanded['canvas']);

        self::assertSame([], RepeatableRows::inferredCountsFor($containers, []), 'nothing addressed → no opinion (the default applies)');
        self::assertSame([], RepeatableRows::inferredCountsFor($containers, [self::A, self::HEAD]), 'slot 0 alone → no opinion');
        self::assertSame([self::ROW => 2], RepeatableRows::inferredCountsFor($containers, [self::OLD_A2]));
        self::assertSame([self::ROW => 3], RepeatableRows::inferredCountsFor($containers, [self::OLD_A2, self::OLD_A3]));
        self::assertSame([self::ROW => 2], RepeatableRows::inferredCountsFor($containers, [self::OLD_ROW2]), 'a hide of the old row container addresses its slot');
        self::assertSame([self::ROW => 4], RepeatableRows::inferredCountsFor($containers, [RepeatableRows::slotId(self::ROW, self::A, 3)]));

        // Never below the designer's default, never above max.
        $defaultFour = CanvasContainer::collectionFromCanvas($this->expand(['max' => 5, 'default' => 4, 'slots' => [[self::A => self::OLD_A2]]])['canvas']);
        self::assertSame([self::ROW => 4], RepeatableRows::inferredCountsFor($defaultFour, [self::OLD_A2]));
    }

    public function testTheVoParsesSlotsDefensivelyAndKeepsThemOutOfTheLimitsView(): void
    {
        $repeat = ContainerRepeat::fromArray(['max' => 4, 'default' => 2, 'slots' => [
            ['a' => 'b', 'bad' => 5, '' => 'x'],
            'not a map',
            ['c' => 'd'],
            [],
        ]]);
        self::assertNotNull($repeat);
        self::assertSame([['a' => 'b'], [], ['c' => 'd']], $repeat->slots, 'a malformed entry keeps its position, trailing empties go');
        self::assertSame('b', $repeat->legacyId(1, 'a'));
        self::assertNull($repeat->legacyId(2, 'a'));
        self::assertNull($repeat->legacyId(0, 'a'));
        self::assertSame(['max' => 4, 'min' => 1, 'default' => 2], $repeat->toArray());
        self::assertSame(['max' => 4, 'min' => 1, 'default' => 2, 'slots' => [['a' => 'b'], [], ['c' => 'd']]], $repeat->toStoredArray());

        $plain = ContainerRepeat::fromArray(['max' => 4, 'slots' => 'nope']);
        self::assertNotNull($plain);
        self::assertSame([], $plain->slots);
        self::assertArrayNotHasKey('slots', $plain->toStoredArray(), 'an unmerged row stores exactly what it did before');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<string, mixed> $repeat
     * @return array{canvas: array<array-key, mixed>, inputs: list<EditorTextInput>, imageInputs: list<\WBoost\Web\Value\EditorImageInput>}
     */
    private function expand(array $repeat): array
    {
        $text = static fn (string $id, float $top): array => [
            'type' => 'Textbox', 'left' => 40, 'top' => $top, 'width' => 300, 'height' => 40,
            'fontSize' => 30, 'text' => 'x', 'inputId' => $id,
        ];
        $canvas = [
            'version' => '7.3.1',
            'objects' => [$text(self::HEAD, 20), $text(self::A, 100), $text(self::FOOT, 200)],
            'containers' => [
                ['id' => self::ROW, 'maxHeight' => 40, 'memberInputIds' => [self::A], 'memberContainerIds' => [], 'repeat' => $repeat],
                ['id' => self::PARENT, 'maxHeight' => 900, 'gap' => 10, 'memberInputIds' => [self::FOOT], 'memberContainerIds' => [self::ROW]],
            ],
        ];
        $input = static fn (string $id, string $name): EditorTextInput => EditorTextInput::fromArray([
            'inputId' => $id, 'name' => $name, 'description' => '', 'sampleValue' => $name,
            'locked' => false, 'hidable' => true, 'uppercase' => false, 'maxLength' => null,
            'richText' => false, 'lists' => false, 'checklist' => false, 'fontChoice' => false,
            'allowedFonts' => [], 'allowedColors' => null,
        ]);

        $expanded = RepeatableRows::expandCanvas($canvas, [$input(self::HEAD, 'Nadpis'), $input(self::A, 'Střelec'), $input(self::FOOT, 'Patička')], []);
        self::assertNotNull($expanded);

        return $expanded;
    }

    /**
     * @param array<array-key, mixed> $canvas
     * @return list<null|string>
     */
    private function objectIds(array $canvas): array
    {
        $objects = $canvas['objects'];
        \assert(is_array($objects));

        return array_values(array_map(
            static fn (mixed $o): null|string => is_array($o) && is_string($o['inputId'] ?? null) ? $o['inputId'] : null,
            $objects,
        ));
    }
}
