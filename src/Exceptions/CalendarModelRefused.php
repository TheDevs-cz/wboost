<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * The calendar model (the JavaScript that owns layouts and generation, run
 * on the server by {@see \WBoost\Web\Services\Calendar\CalendarModelBridge})
 * refused a requested change: an unknown style key, a font the project does
 * not have, a preset that does not fit the type… `reason` is a stable code,
 * the message is English text written for the caller of an MCP tool.
 */
final class CalendarModelRefused extends \Exception
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
