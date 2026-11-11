<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

use Ramsey\Uuid\UuidInterface;

/**
 * The role a calendar request acts in, resolved once per request by
 * {@see \WBoost\Web\Services\Calendar\CalendarAccess}. `userId` is null for
 * the anonymous holder of the client link.
 */
readonly final class CalendarActor
{
    public function __construct(
        public CalendarAuthorKind $kind,
        public null|UuidInterface $userId = null,
    ) {
    }

    public static function designer(null|UuidInterface $userId = null): self
    {
        return new self(CalendarAuthorKind::Designer, $userId);
    }

    public static function client(null|UuidInterface $userId = null): self
    {
        return new self(CalendarAuthorKind::Client, $userId);
    }

    public function isDesigner(): bool
    {
        return $this->kind === CalendarAuthorKind::Designer;
    }
}
