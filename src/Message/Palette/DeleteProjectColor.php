<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;

/**
 * "Smazat barvu" (F7 P2) behind the delete guard: a logo colour is never
 * deleted; one manuals added only with `removeFromManuals`.
 */
readonly final class DeleteProjectColor
{
    public function __construct(
        public UuidInterface $colorId,
        public bool $removeFromManuals,
    ) {
    }
}
