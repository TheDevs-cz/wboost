<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

/**
 * "Is the headless renderer working right now?" — asked after a render that
 * did not come back, to tell a renderer that is down or overloaded from a
 * page that is too heavy to draw.
 */
interface RendererProbeInterface
{
    /**
     * True when the renderer drew a trivial page within a few seconds.
     */
    public function responds(): bool;
}
