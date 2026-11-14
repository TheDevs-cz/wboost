<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use WBoost\Web\Services\Editor\RendererProbeInterface;

/**
 * No Gotenberg in the suite: the answer is whatever the test says (the
 * renderer works unless told otherwise).
 */
final class FakeRendererProbe implements RendererProbeInterface
{
    public bool $responds = true;

    public int $asked = 0;

    public function responds(): bool
    {
        $this->asked++;

        return $this->responds;
    }
}
