<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * The server-side row model (container-rows plan P2): expansion of a
 * repeatable nested container into `max` materialised rows, the derived ids,
 * the positional `inputs[]` invariant and the unused-row hide expansion.
 *
 * The synthetic design (600 × 1200), top to bottom:
 *
 *     objects[0] HEAD     text  top 20   h 40    (bound, inputs[0])
 *     objects[1] GHOST    text  top 30   h 40    DESIGN-hidden (no input)
 *     objects[2] A        text  top 100  h 40  ┐ row ROW (repeat) inside PARENT
 *     objects[3] B        text  top 105  h 30  │
 *     objects[4] ICON     image top 100  h 40  ┘ decorative, A's attachment
 *     objects[5] SLOT     image top 100  h 40    fillable slot of the row
 *     objects[6] FOOT     text  top 200  h 40    PARENT's footer (designed gap 60)
 *     objects[7] TAIL     text  top 600  h 40    outside every container
 *
 * PARENT is a root (maxHeight 900) of the row + FOOT, designed gaps.
 *
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 */
final class RepeatableRowsTest extends TestCase
{
    private const string HEAD = '01900000-0000-7000-8000-000000000001';
    private const string A = '01900000-0000-7000-8000-000000000002';
    private const string B = '01900000-0000-7000-8000-000000000003';
    private const string ICON = '01900000-0000-7000-8000-000000000004';
    private const string SLOT = '01900000-0000-7000-8000-000000000005';
    private const string FOOT = '01900000-0000-7000-8000-000000000006';
    private const string TAIL = '01900000-0000-7000-8000-000000000007';
    private const string GHOST = '01900000-0000-7000-8000-000000000008';
    private const string ROW = 'row';
    private const string PARENT = 'parent';

    public function testADesignWithoutRepeatIsUntouched(): void
    {
        $canvas = $this->canvas(repeat: null);

        self::assertNull(RepeatableRows::expandCanvas($canvas, $this->inputs(), $this->imageInputs()));
    }

    public function testSlotIdsAreDeterministicAndSlotZeroKeepsTheOriginal(): void
    {
        self::assertSame(self::A, RepeatableRows::slotId(self::ROW, self::A, 0));
        self::assertSame(RepeatableRows::slotId(self::ROW, self::A, 2), RepeatableRows::slotId(self::ROW, self::A, 2));
        self::assertNotSame(RepeatableRows::slotId(self::ROW, self::A, 1), RepeatableRows::slotId(self::ROW, self::A, 2));
        self::assertNotSame(RepeatableRows::slotId(self::ROW, self::A, 1), RepeatableRows::slotId('other-row', self::A, 1));
        // Pinned: derived ids are fill-state keys (stored versions!) and must never drift.
        self::assertSame('3bada3cf-6b1f-5088-a714-0d12e7f5392d', RepeatableRows::slotId(self::ROW, self::A, 1));
    }

    public function testExpansionMaterialisesMaxRowsWithDerivedIds(): void
    {
        $expanded = $this->expanded(max: 3);
        $ids = $this->objectIds($expanded['canvas']);

        $a1 = RepeatableRows::slotId(self::ROW, self::A, 1);
        $a2 = RepeatableRows::slotId(self::ROW, self::A, 2);
        $slot2 = RepeatableRows::slotId(self::ROW, self::SLOT, 2);

        // Clones sit right after the row's last member, slot by slot, in the
        // row's own z-order; everything else keeps its relative place.
        self::assertSame([
            self::HEAD, self::GHOST, self::A, self::B, self::ICON, self::SLOT,
            $a1, RepeatableRows::slotId(self::ROW, self::B, 1), RepeatableRows::slotId(self::ROW, self::ICON, 1), RepeatableRows::slotId(self::ROW, self::SLOT, 1),
            $a2, RepeatableRows::slotId(self::ROW, self::B, 2), RepeatableRows::slotId(self::ROW, self::ICON, 2), $slot2,
            self::FOOT, self::TAIL,
        ], $ids);

        // Pitch = row extent (100…140 = 40) + the designed gap to the footer
        // (200 − 140 = 60) = 100.
        $tops = $this->tops($expanded['canvas']);
        self::assertSame(100.0, $tops[self::A]);
        self::assertSame(200.0, $tops[$a1]);
        self::assertSame(305.0, $tops[RepeatableRows::slotId(self::ROW, self::B, 2)]);
        self::assertSame(300.0, $tops[$slot2]);
        // The footer moved by (max − 1) × pitch: its designed gap to the LAST
        // row is the designed gap it had to the template row.
        self::assertSame(400.0, $tops[self::FOOT]);
        self::assertSame(600.0, $tops[self::TAIL], 'outside every container — untouched');
        self::assertSame(20.0, $tops[self::HEAD]);
    }

    public function testContainerDefinitionsCarrySlotsAndTheParentFlowsThem(): void
    {
        $expanded = $this->expanded(max: 3);
        $containers = CanvasContainer::collectionFromCanvas($expanded['canvas']);
        $row = CanvasContainer::findById($containers, self::ROW);
        $parent = CanvasContainer::findById($containers, self::PARENT);
        $slot1 = RepeatableRows::slotId(self::ROW, self::ROW, 1);
        $slot2 = RepeatableRows::slotId(self::ROW, self::ROW, 2);

        self::assertNotNull($row);
        self::assertNotNull($parent);
        self::assertSame([self::ROW, $slot1, $slot2], $row->repeatSlotIds);
        self::assertSame([self::ROW, $slot1, $slot2], $parent->memberContainerIds);

        $first = CanvasContainer::findById($containers, $slot1);
        self::assertNotNull($first);
        self::assertSame(self::ROW, $first->repeatSlotOf);
        self::assertNull($first->repeat, 'a slot is never repeatable itself');
        self::assertSame(
            [RepeatableRows::slotId(self::ROW, self::A, 1), RepeatableRows::slotId(self::ROW, self::B, 1), RepeatableRows::slotId(self::ROW, self::ICON, 1), RepeatableRows::slotId(self::ROW, self::SLOT, 1)],
            $first->memberInputIds,
        );
    }

    public function testInputsAreRebuiltInTextboxOrderSoThePositionalBindingHolds(): void
    {
        $expanded = $this->expanded(max: 3);

        $inputIds = array_map(static fn (EditorTextInput $i): string => $i->inputId, $expanded['inputs']);
        self::assertSame([
            self::HEAD, self::A, self::B,
            RepeatableRows::slotId(self::ROW, self::A, 1), RepeatableRows::slotId(self::ROW, self::B, 1),
            RepeatableRows::slotId(self::ROW, self::A, 2), RepeatableRows::slotId(self::ROW, self::B, 2),
            self::FOOT, self::TAIL,
        ], $inputIds);

        // The binder (what the renderer re-stamps with) agrees object by object.
        $objects = $expanded['canvas']['objects'];
        \assert(is_array($objects));
        foreach (TextInputObjectBinder::positionalBinding($expanded['canvas'], $expanded['inputs']) as $index => $inputId) {
            $object = $objects[$index];
            \assert(is_array($object));
            self::assertSame($inputId, $object['inputId']);
        }

        // A new row starts from the template row's settings + sample text.
        $clone = $expanded['inputs'][3];
        self::assertSame('A sample', $clone->sampleValue);
        self::assertSame('Skóre', $clone->name);
        self::assertTrue($clone->hidable);
    }

    public function testImageSlotsOfTheRowAreClonedWithTheirSettings(): void
    {
        $expanded = $this->expanded(max: 3);

        self::assertSame(
            [self::SLOT, RepeatableRows::slotId(self::ROW, self::SLOT, 1), RepeatableRows::slotId(self::ROW, self::SLOT, 2)],
            array_map(static fn (EditorImageInput $i): string => $i->inputId, $expanded['imageInputs']),
        );
        $clone = $expanded['imageInputs'][2];
        self::assertSame('Logo', $clone->name);
        self::assertTrue($clone->allowMove);
        self::assertTrue($clone->hidable);
    }

    public function testExpansionIsIdempotent(): void
    {
        $expanded = $this->expanded(max: 3);

        self::assertNull(RepeatableRows::expandCanvas($expanded['canvas'], $expanded['inputs'], $expanded['imageInputs']));
    }

    public function testUnusedRowsAreHiddenThroughTheExistingHideMap(): void
    {
        $expanded = $this->expanded(max: 3, default: 1);
        $containers = CanvasContainer::collectionFromCanvas($expanded['canvas']);
        $slotMembers = static fn (int $slot): array => array_map(
            static fn (string $id): string => RepeatableRows::slotId(self::ROW, $id, $slot),
            [self::A, self::B, self::ICON, self::SLOT],
        );

        // No count → the designer's default (1): slots 1 and 2 hidden.
        $default = (new ResolvedInputOverrides([], []))->effectiveHidden($containers);
        self::assertSame(array_fill_keys([...$slotMembers(1), ...$slotMembers(2)], true), $default);

        // Count 2 → only slot 2; out of range → clamped to [min, max].
        self::assertSame(array_fill_keys($slotMembers(2), true), (new ResolvedInputOverrides([], []))->withRowCounts([self::ROW => 2])->effectiveHidden($containers));
        self::assertSame([], (new ResolvedInputOverrides([], []))->withRowCounts([self::ROW => 99])->effectiveHidden($containers));
        self::assertCount(8, (new ResolvedInputOverrides([], []))->withRowCounts([self::ROW => 0])->effectiveHidden($containers));

        // An unused row wins over a member's own "shown" state; withShapeColors keeps the counts.
        $overrides = (new ResolvedInputOverrides([], [$slotMembers(2)[0] => false]))->withRowCounts([self::ROW => 2])->withShapeColors([]);
        self::assertTrue($overrides->effectiveHidden($containers)[$slotMembers(2)[0]]);
    }

    public function testCountsAreNormalizedPerRow(): void
    {
        $expanded = $this->expanded(max: 4, default: 2, min: 2);
        $containers = CanvasContainer::collectionFromCanvas($expanded['canvas']);
        $row = CanvasContainer::findById($containers, self::ROW);
        self::assertNotNull($row?->repeat);

        self::assertSame(2, $row->repeat->clamp(null));
        self::assertSame(2, $row->repeat->clamp(1));
        self::assertSame(4, $row->repeat->clamp(7));
        self::assertSame(3, $row->repeat->clamp(3));

        $map = RepeatableRows::slotMemberMap($containers);
        self::assertSame(['max' => 4, 'min' => 2, 'default' => 2], array_diff_key($map[self::ROW], ['slots' => true]));
        self::assertCount(4, $map[self::ROW]['slots']);
        self::assertSame([self::A, self::B, self::ICON, self::SLOT], $map[self::ROW]['slots'][0]);
    }

    public function testParentGapWinsOverTheDesignedGap(): void
    {
        $expanded = $this->expanded(max: 2, parentGap: 10.0);
        $tops = $this->tops($expanded['canvas']);

        // pitch = 40 + 10 → slot 1 at 150; the footer still shifts by one pitch.
        self::assertSame(150.0, $tops[RepeatableRows::slotId(self::ROW, self::A, 1)]);
        self::assertSame(250.0, $tops[self::FOOT]);
    }

    public function testItemsBelowTheBranchMoveInEveryAncestor(): void
    {
        // Wrap PARENT into an OUTER root together with TAIL (below it).
        $canvas = $this->canvas(repeat: ['max' => 3, 'min' => 1, 'default' => 1]);
        $containers = $canvas['containers'];
        \assert(is_array($containers));
        $containers[] = ['id' => 'outer', 'maxHeight' => 1100, 'memberInputIds' => [self::TAIL], 'memberContainerIds' => [self::PARENT]];
        $canvas['containers'] = $containers;

        $expanded = RepeatableRows::expandCanvas($canvas, $this->inputs(), $this->imageInputs());
        self::assertNotNull($expanded);
        $tops = $this->tops($expanded['canvas']);

        self::assertSame(400.0, $tops[self::FOOT]);
        self::assertSame(800.0, $tops[self::TAIL], 'below the branch in the outer root');
        self::assertSame(20.0, $tops[self::HEAD]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, bool}>
     */
    public static function repeatRules(): iterable
    {
        yield 'valid' => [['max' => 5], true];
        yield 'min defaults 1, default defaults min' => [['max' => 5, 'min' => 2], true];
        yield 'max over the cap' => [['max' => 31], false];
        yield 'min 0' => [['max' => 5, 'min' => 0], false];
        yield 'default above max' => [['max' => 3, 'default' => 4], false];
        yield 'default below min' => [['max' => 3, 'min' => 2, 'default' => 1], false];
        yield 'not an integer' => [['max' => 2.5], false];
        yield 'no max' => [['min' => 1], false];
    }

    /**
     * @param array<string, mixed>|null $repeat
     */
    #[DataProvider('repeatRules')]
    public function testRepeatValidation(null|array $repeat, bool $valid): void
    {
        $row = CanvasContainer::findById(CanvasContainer::collectionFromCanvas($this->canvas(repeat: $repeat)), self::ROW);

        self::assertNotNull($row);
        self::assertSame($valid, $row->repeat !== null);
    }

    public function testRepeatIsHonouredOnlyOnANestedRowOncePerParent(): void
    {
        $base = $this->canvas(repeat: ['max' => 3]);
        /** @var list<array<string, mixed>> $containers */
        $containers = $base['containers'];

        // On a root: dropped (the parent is the flow and the bound).
        $root = $base;
        $root['containers'] = array_map(
            static fn (array $c): array => $c['id'] === self::PARENT ? $c + ['repeat' => ['max' => 3]] : $c,
            $containers,
        );
        $parent = CanvasContainer::findById(CanvasContainer::collectionFromCanvas($root), self::PARENT);
        self::assertNotNull($parent);
        self::assertNull($parent->repeat);

        // A second repeatable child of the same parent loses `repeat`; being
        // a single text it is then degenerate and dropped.
        $two = $base;
        $two['containers'] = [
            ...array_map(
                static fn (array $c): array => $c['id'] === self::PARENT ? ['memberContainerIds' => [self::ROW, 'second']] + $c : $c,
                $containers,
            ),
            ['id' => 'second', 'maxHeight' => 50, 'memberInputIds' => [self::FOOT], 'memberContainerIds' => [], 'repeat' => ['max' => 2]],
        ];
        $collection = CanvasContainer::collectionFromCanvas($two);
        self::assertNotNull(CanvasContainer::findById($collection, self::ROW)?->repeat);
        self::assertNull(CanvasContainer::findById($collection, 'second'));
    }

    public function testASingleTextRowIsAContainerOnlyWhenRepeatable(): void
    {
        $canvas = $this->canvas(repeat: ['max' => 3]);
        /** @var list<array<string, mixed>> $containers */
        $containers = $canvas['containers'];
        $canvas['containers'] = array_map(
            static fn (array $c): array => $c['id'] === self::ROW ? ['memberInputIds' => [self::A]] + $c : $c,
            $containers,
        );
        self::assertNotNull(CanvasContainer::findById(CanvasContainer::collectionFromCanvas($canvas), self::ROW));

        /** @var list<array<string, mixed>> $rowOnly */
        $rowOnly = $canvas['containers'];
        $canvas['containers'] = array_map(
            static function (array $c): array {
                unset($c['repeat']);

                return $c;
            },
            $rowOnly,
        );
        self::assertNull(CanvasContainer::findById(CanvasContainer::collectionFromCanvas($canvas), self::ROW));
    }

    public function testToArrayEmitsTheRowKeysOnlyWhenSet(): void
    {
        $plain = CanvasContainer::fromArray(['id' => 'c', 'maxHeight' => 10, 'memberInputIds' => ['a', 'b']]);
        self::assertNotNull($plain);
        self::assertArrayNotHasKey('repeat', $plain->toArray());

        $expanded = $this->expanded(max: 2);
        $row = CanvasContainer::findById(CanvasContainer::collectionFromCanvas($expanded['canvas']), self::ROW);
        self::assertNotNull($row);
        self::assertSame(['max' => 2, 'min' => 1, 'default' => 1], $row->toArray()['repeat'] ?? null);
        self::assertCount(2, $row->toArray()['repeatSlots'] ?? []);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return array{canvas: array<array-key, mixed>, inputs: list<EditorTextInput>, imageInputs: list<EditorImageInput>}
     */
    private function expanded(int $max, int $default = 1, int $min = 1, null|float $parentGap = null): array
    {
        $expanded = RepeatableRows::expandCanvas(
            $this->canvas(['max' => $max, 'min' => $min, 'default' => $default], $parentGap),
            $this->inputs(),
            $this->imageInputs(),
        );
        self::assertNotNull($expanded);

        return $expanded;
    }

    /**
     * @param null|array<string, mixed> $repeat
     * @return array<string, mixed>
     */
    private function canvas(null|array $repeat, null|float $parentGap = null): array
    {
        $text = static fn (null|string $id, float $top, float $height, bool $visible = true): array => [
            'type' => 'Textbox', 'left' => 40, 'top' => $top, 'width' => 300, 'height' => $height,
            'fontSize' => 30, 'text' => 'x', 'visible' => $visible,
        ] + ($id !== null ? ['inputId' => $id] : []);

        $row = ['id' => self::ROW, 'maxHeight' => 40, 'memberInputIds' => [self::A, self::B, self::ICON, self::SLOT], 'memberContainerIds' => []];
        if ($repeat !== null) {
            $row['repeat'] = $repeat;
        }
        $parent = ['id' => self::PARENT, 'maxHeight' => 900, 'memberInputIds' => [self::FOOT], 'memberContainerIds' => [self::ROW]];
        if ($parentGap !== null) {
            $parent['gap'] = $parentGap;
        }

        return [
            'version' => '7.3.1',
            'objects' => [
                $text(self::HEAD, 20, 40),
                $text(self::GHOST, 30, 40, false),
                // A carries NO inputId on purpose: binding is positional and
                // the expansion must stamp it before cloning.
                $text(null, 100, 40),
                $text(self::B, 105, 30),
                ['type' => 'Image', 'left' => 360, 'top' => 100, 'width' => 40, 'height' => 40, 'src' => 'data:,', 'inputId' => self::ICON],
                ['type' => 'Image', 'left' => 420, 'top' => 100, 'width' => 20, 'height' => 20, 'scaleY' => 2, 'scaleX' => 2, 'src' => 'data:,', 'imagePlaceholder' => true, 'inputId' => self::SLOT],
                $text(self::FOOT, 200, 40),
                $text(self::TAIL, 600, 40),
            ],
            'containers' => [$row, $parent],
        ];
    }

    /**
     * @return list<EditorTextInput>
     */
    private function inputs(): array
    {
        $input = static fn (string $id, string $name, string $sample): EditorTextInput => EditorTextInput::fromArray([
            'inputId' => $id, 'name' => $name, 'description' => '', 'sampleValue' => $sample,
            'locked' => false, 'hidable' => true, 'uppercase' => false, 'maxLength' => null,
            'richText' => false, 'lists' => false, 'checklist' => false, 'fontChoice' => false,
            'allowedFonts' => [], 'allowedColors' => null,
        ]);

        return [
            $input(self::HEAD, 'Nadpis', 'Head'),
            $input(self::A, 'Skóre', 'A sample'),
            $input(self::B, 'Soupeř', 'B sample'),
            $input(self::FOOT, 'Patička', 'Foot'),
            $input(self::TAIL, 'Konec', 'Tail'),
        ];
    }

    /**
     * @return list<EditorImageInput>
     */
    private function imageInputs(): array
    {
        return [EditorImageInput::fromArray(['inputId' => self::SLOT, 'name' => 'Logo', 'allowMove' => true, 'hidable' => true])];
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

    /**
     * @param array<array-key, mixed> $canvas
     * @return array<string, float>
     */
    private function tops(array $canvas): array
    {
        $tops = [];
        $objects = $canvas['objects'];
        \assert(is_array($objects));
        foreach ($objects as $object) {
            \assert(is_array($object));
            if (is_string($object['inputId'] ?? null)) {
                $tops[$object['inputId']] = is_numeric($object['top'] ?? null) ? (float) $object['top'] : NAN;
            }
        }

        return $tops;
    }
}
