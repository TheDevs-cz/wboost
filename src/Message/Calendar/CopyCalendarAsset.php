<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarActor;

/**
 * Layout import: copies one asset of another calendar (file + preview) into
 * the target calendar under a new id — see
 * {@see \WBoost\Web\Services\Calendar\ImportCalendarLayout}.
 */
readonly final class CopyCalendarAsset
{
    public function __construct(
        public UuidInterface $newAssetId,
        public UuidInterface $sourceAssetId,
        public UuidInterface $targetCalendarId,
        public CalendarActor $actor,
    ) {
    }
}
