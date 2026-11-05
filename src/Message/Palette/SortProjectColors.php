<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;

/**
 * Drag reorder / "Posunout výš / níž" inside one group of Paleta barev
 * (F7 P2) — the colours swap among the palette positions they hold.
 */
readonly final class SortProjectColors
{
    public function __construct(
        public UuidInterface $projectId,
        /** @var array<string> palette colour ids, new order */
        public array $colorIds,
    ) {
    }
}
