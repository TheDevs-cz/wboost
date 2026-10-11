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

    public function testStaleIdsAreIgnoredAndNothingIsDropped(): void
    {
        self::assertSame(['b', 'a'], $this->apply(['a', 'b'], ['gone', 'b', 'also-gone', 'a']));
    }
}
