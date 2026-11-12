<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * Refusal codes of the scanner API (`/internal/scanner/*`). The body is always
 * `{ok: false, code}`; the HTTP status says what the scanner must do with the
 * run: 200 = carry on, 409 = this run is over (abort), 410 = the scan is gone
 * (abort).
 */
enum ScannerApiCode: string
{
    case StaleRun = 'stale_run';
    case LeaseLost = 'lease_lost';
    case ProtocolTooOld = 'protocol_too_old';
    case RendererBusy = 'renderer_busy';
    case RendererUnavailable = 'renderer_unavailable';
    case Gone = 'gone';
    case InvalidRequest = 'invalid_request';
    case CandidateLimit = 'candidate_limit';
    case MissingArtifact = 'missing_artifact';
    case UnsupportedKind = 'unsupported_kind';

    public function httpStatus(): int
    {
        return match ($this) {
            self::StaleRun, self::LeaseLost => 409,
            self::Gone => 410,
            default => 200,
        };
    }
}
