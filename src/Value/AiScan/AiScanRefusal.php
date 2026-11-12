<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * Why a start, retry, continue, cancel or discard was refused — the closed
 * list the web hints and the MCP tool share (architecture.md §5.6).
 */
enum AiScanRefusal: string
{
    case NotEnabled = 'not_enabled';
    case ConsentRequired = 'consent_required';
    case LimitReached = 'limit_reached';
    case DailyStartsReached = 'daily_starts_reached';
    case ProjectQueueFull = 'project_queue_full';
    case QueueFull = 'queue_full';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case ProjectPaused = 'project_paused';
    case NoFonts = 'no_fonts';
    case UnsupportedImage = 'unsupported_image';
    case SourceUnscannable = 'source_unscannable';
    case NoRetriesLeft = 'no_retries_left';
    /** The scan is not in a state the action applies to (a lost race, or a stale page). */
    case WrongState = 'wrong_state';
    // Accept, restore and export refusals (used from P3 / P5 on).
    case StaleReferences = 'stale_references';
    case RendererBusy = 'renderer_busy';
    case UnfilledPictures = 'unfilled_pictures';
    case LayoutChanged = 'layout_changed';

    public function httpStatus(): int
    {
        return match ($this) {
            self::NotEnabled => 403,
            self::UnsupportedImage, self::ConsentRequired, self::NoFonts => 422,
            self::TemporarilyUnavailable, self::RendererBusy => 503,
            self::LimitReached, self::DailyStartsReached, self::ProjectQueueFull, self::QueueFull => 429,
            default => 409,
        };
    }
}
