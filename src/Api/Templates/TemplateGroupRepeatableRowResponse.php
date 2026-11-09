<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * A repeatable row of a template GROUP: the ONE row count the group fill
 * shares across every dimension. `fitsRows` is the SMALLEST member's
 * designed estimate — the smallest size decides whether another row fits.
 */
final readonly class TemplateGroupRepeatableRowResponse
{
    /**
     * @param array{max: int, min: int, default: int} $repeat
     */
    public function __construct(
        public string $containerId,
        public array $repeat,
        public null|int $fitsRows,
    ) {
    }
}
