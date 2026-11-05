<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

/**
 * What the projects dashboard needs to know about one project beyond the
 * entity itself: manual count (the "S manuálem / Bez manuálu" filter) and the
 * completeness flags behind the card's grey warning triangle.
 */
readonly final class ProjectCardRow
{
    public function __construct(
        public int $manualsCount,
        /** The project palette (Paleta barev, F7) has at least one colour. */
        public bool $hasPalette,
        public int $fontsCount,
    ) {
    }

    /**
     * Human-readable list of what the project still lacks, e.g.
     * ["barevná paleta", "písma"]. Empty = complete.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->hasPalette === false) {
            $missing[] = 'barevná paleta';
        }

        if ($this->fontsCount === 0) {
            $missing[] = 'písma';
        }

        return $missing;
    }
}
