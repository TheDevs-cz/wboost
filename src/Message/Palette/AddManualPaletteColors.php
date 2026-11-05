<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Palette;

use Ramsey\Uuid\UuidInterface;

/**
 * "Přidat z palety" in the "Barvy v manuálech" box (F7 P3): the picked
 * palette colours join the END of the manual's colour order as added
 * colours. Colours the manual already has (or of another project) are skipped.
 */
readonly final class AddManualPaletteColors
{
    public function __construct(
        public UuidInterface $manualId,
        /** @var array<string> palette colour ids, in the order to append them */
        public array $colorIds,
    ) {
    }
}
