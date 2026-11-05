<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;

/**
 * "Sloučit" (F7 P4): the palette colours of `loserIds` — same HEX as the
 * survivor, same project — merge into `survivorId` ("fill the gap": the
 * survivor's empty CMYK / Pantone / RAL / Název are filled, a filled value is
 * never overwritten), their manual links move to the survivor, then they are
 * deleted.
 */
readonly final class MergeProjectColors
{
    public function __construct(
        public UuidInterface $survivorId,
        /** @var list<UuidInterface> */
        public array $loserIds,
    ) {
    }
}
