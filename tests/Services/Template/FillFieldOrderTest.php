<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Template\FillFieldOrder;

final class FillFieldOrderTest extends TestCase
{
    /**
     * @param list<string> $fields
     * @param list<string> $order
     *
     * @return list<string>
     */
    private function apply(array $fields, array $order): array
    {
        return (new FillFieldOrder())->apply($fields, static fn (string $id): string => $id, $order);
    }

    public function testEmptyOrderKeepsNaturalOrder(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->apply(['a', 'b', 'c'], []));
    }

    public function testArrangedFieldsFollowTheStoredOrder(): void
    {
        self::assertSame(['c', 'a', 'b'], $this->apply(['a', 'b', 'c'], ['c', 'a', 'b']));
    }

    public function testFieldsAddedAfterTheArrangementFollowInNaturalOrder(): void
    {
        self::assertSame(['c', 'a', 'b', 'd'], $this->apply(['a', 'b', 'c', 'd'], ['c', 'a']));
    }

    public function testARowsFieldsStayTogetherRowByRowWhereTheRowRanks(): void
    {
        // Natural order as the page builds it: texts (rows grouped), then
        // images (the row's logos at the end), then a hide toggle.
        $fields = ['title', 'score-0', 'team-0', 'score-1', 'team-1', 'footer', 'logo-0', 'logo-1'];
        $rows = [
            'score-0' => ['row' => 'r', 'role' => 'score-0', 'slot' => 0],
            'team-0' => ['row' => 'r', 'role' => 'team-0', 'slot' => 0],
            'logo-0' => ['row' => 'r', 'role' => 'logo-0', 'slot' => 0],
            'score-1' => ['row' => 'r', 'role' => 'score-0', 'slot' => 1],
            'team-1' => ['row' => 'r', 'role' => 'team-0', 'slot' => 1],
            'logo-1' => ['row' => 'r', 'role' => 'logo-0', 'slot' => 1],
        ];

        self::assertSame(
            ['title', 'score-0', 'team-0', 'logo-0', 'score-1', 'team-1', 'logo-1', 'footer'],
            (new FillFieldOrder())->apply($fields, static fn (string $id): string => $id, [], $rows),
            'Without an arrangement: natural order, the row block in one piece.',
        );

        // Arranged: the admin put the logo before the team in row 1 and the
        // row after the footer — every row follows the designed row's order.
        self::assertSame(
            ['title', 'footer', 'logo-0', 'score-0', 'team-0', 'logo-1', 'score-1', 'team-1'],
            (new FillFieldOrder())->apply($fields, static fn (string $id): string => $id, ['title', 'footer', 'logo-0', 'score-0', 'team-0'], $rows),
        );
    }

    public function testStaleIdsAreIgnoredAndNothingIsDropped(): void
    {
        self::assertSame(['b', 'a'], $this->apply(['a', 'b'], ['gone', 'b', 'also-gone', 'a']));
    }
}
