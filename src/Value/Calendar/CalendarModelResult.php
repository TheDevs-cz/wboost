<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * What one run of the server-side calendar model answers
 * ({@see \WBoost\Web\Services\Calendar\CalendarModelBridge}).
 */
readonly final class CalendarModelResult
{
    /**
     * @param null|string $payload the save payload the workspace would POST (`{ design?, content? }`
     *   as JSON, ready for {@see CalendarSavePayload::fromJson()}); null when the commands changed nothing
     * @param list<string> $notes what the model did beyond the literal request (clamped values, a mirrored layout…)
     * @param \stdClass $design the fully materialised design AFTER the commands (page, print, features, layout… with every default)
     * @param null|list<array{index: int, key: string, kind: string, title: string, subtitle: string, from?: string, to?: string}> $pages
     * @param null|list<array{page: string, kind: string, message: string}> $warnings
     * @param null|array{x: float, y: float, w: float, h: float} $view the mm view box of a rendered page
     */
    public function __construct(
        public null|string $payload,
        public array $notes,
        public \stdClass $design,
        public null|array $pages,
        public null|array $warnings,
        public null|array $view,
    ) {
    }

    public function hasLayout(): bool
    {
        return ($this->design->layout ?? null) instanceof \stdClass;
    }
}
