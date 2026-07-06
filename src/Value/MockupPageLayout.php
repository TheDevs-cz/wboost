<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum MockupPageLayout: string
{
    case Layout1 = 'layout-1';
    case Layout2 = 'layout-2';
    case Layout3 = 'layout-3';
    case Layout4 = 'layout-4';
    case Layout5 = 'layout-5';
    case Layout6 = 'layout-6';
    case Layout7 = 'layout-7';
    case Layout8 = 'layout-8';
    case Layout9 = 'layout-9';
    case Layout10 = 'layout-10';
    case Layout11 = 'layout-11';

    public function uploadInputsCount(): int
    {
        return count($this->slots());
    }

    public static function maxUploadInputsCount(): int
    {
        $max = 0;

        foreach (self::cases() as $layout) {
            $max = max($max, $layout->uploadInputsCount());
        }

        return $max;
    }

    /**
     * Slot order matches the persisted ManualMockupPage::$images indexes.
     *
     * @return list<MockupPageSlot>
     */
    public function slots(): array
    {
        return match ($this) {
            self::Layout1 => [
                new MockupPageSlot(1, 1, 1, 1),
                new MockupPageSlot(2, 2, 1, 1),
                new MockupPageSlot(1, 2, 2, 1),
                new MockupPageSlot(3, 1, 2, 1),
            ],
            self::Layout2 => [
                new MockupPageSlot(1, 1, 1, 2),
                new MockupPageSlot(2, 2, 1, 1),
                new MockupPageSlot(2, 2, 2, 1),
            ],
            self::Layout3 => [
                new MockupPageSlot(1, 1, 1, 2),
                new MockupPageSlot(2, 1, 1, 2),
                new MockupPageSlot(3, 1, 1, 2),
            ],
            self::Layout4 => [
                new MockupPageSlot(1, 1, 1, 2),
                new MockupPageSlot(2, 1, 1, 1),
                new MockupPageSlot(3, 1, 1, 1),
                new MockupPageSlot(2, 1, 2, 1),
                new MockupPageSlot(3, 1, 2, 1),
            ],
            self::Layout5 => [
                new MockupPageSlot(1, 1, 1, 2),
                new MockupPageSlot(2, 1, 1, 1),
                new MockupPageSlot(3, 1, 1, 1),
                new MockupPageSlot(2, 2, 2, 1),
            ],
            self::Layout6 => [
                new MockupPageSlot(1, 1, 1, 1),
                new MockupPageSlot(2, 1, 1, 1),
                new MockupPageSlot(3, 1, 1, 1),
                new MockupPageSlot(1, 1, 2, 1),
                new MockupPageSlot(2, 1, 2, 1),
                new MockupPageSlot(3, 1, 2, 1),
            ],
            self::Layout7 => [
                new MockupPageSlot(1, 3, 1, 2),
            ],
            self::Layout8 => [
                new MockupPageSlot(1, 1, 1, 2),
                new MockupPageSlot(2, 2, 1, 2),
            ],
            self::Layout9 => [
                new MockupPageSlot(1, 2, 1, 2),
                new MockupPageSlot(3, 1, 1, 2),
            ],
            self::Layout10 => [
                new MockupPageSlot(1, 2, 1, 2),
                new MockupPageSlot(3, 1, 1, 1),
                new MockupPageSlot(3, 1, 2, 1),
            ],
            self::Layout11 => [
                new MockupPageSlot(1, 1, 1, 1),
                new MockupPageSlot(1, 1, 2, 1),
                new MockupPageSlot(2, 2, 1, 2),
            ],
        };
    }

    /**
     * Geometry of every layout in one structure, for the JS editor.
     *
     * @return array<string, list<array{column: int, columnSpan: int, row: int, rowSpan: int, width: int, height: int, recommendedWidth: int, recommendedHeight: int}>>
     */
    public static function exportGeometry(): array
    {
        $geometry = [];

        foreach (self::cases() as $layout) {
            $geometry[$layout->value] = array_map(
                static fn (MockupPageSlot $slot): array => $slot->toArray(),
                $layout->slots(),
            );
        }

        return $geometry;
    }
}
