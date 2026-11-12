<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/** Why a scan `failed` — always a system-side cause. */
enum AiScanErrorCode: string
{
    case ProviderUnavailable = 'provider_unavailable';
    case RendererUnavailable = 'renderer_unavailable';
    case Timeout = 'timeout';
    case Interrupted = 'interrupted';
    case QueueTimeout = 'queue_timeout';
    case Build = 'build';

    /**
     * Whether a `finish(failed)` with this code says something about the
     * PICTURE (architecture.md §5.4): two counted failures make the source
     * unscannable in the project for 30 days. A run cap and a build dead end
     * do; an unavailable provider or renderer, a queue timeout and the
     * admin's kill switch do not. (`interrupted` after a SECOND lost lease
     * and an intake that died twice also count — the reaper writes those
     * itself.)
     */
    public function countsAgainstSource(): bool
    {
        return in_array($this, [self::Timeout, self::Build], true);
    }

    /**
     * The codes a scanner may report through `finish`; the other two are
     * written by the app alone.
     *
     * @return list<self>
     */
    public static function reportable(): array
    {
        return [self::ProviderUnavailable, self::RendererUnavailable, self::Timeout, self::Build];
    }
}
