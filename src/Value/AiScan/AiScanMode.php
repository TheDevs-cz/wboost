<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * The operating mode in the runtime row. `paused` = no new uploads and no new
 * runs; `off` = also fails every scan in progress as interrupted.
 */
enum AiScanMode: string
{
    case On = 'on';
    case Paused = 'paused';
    case Off = 'off';
}
