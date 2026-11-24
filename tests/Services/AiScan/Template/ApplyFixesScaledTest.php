<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan\Template;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\AiScan\Template\ApplyFixes;

/**
 * {@see ApplyFixes::scaled()} — a scan's design document taken to a larger
 * format: every px length scales, including the nested `follows.gap` of a
 * container attached below another ("Navázat pod").
 */
final class ApplyFixesScaledTest extends TestCase
{
    public function testAContainerFollowsGapScalesWithTheCanvas(): void
    {
        $scaled = ApplyFixes::scaled([
            'canvas' => ['width' => 1000, 'height' => 1000],
            'elements' => [
                ['kind' => 'container', 'id' => 'list', 'members' => [], 'children' => ['row'], 'maxHeight' => 400, 'gap' => 20],
                ['kind' => 'container', 'id' => 'footer', 'members' => ['a', 'b'], 'maxHeight' => 100, 'follows' => ['container' => 'list', 'gap' => 15]],
                ['kind' => 'container', 'id' => 'tail', 'members' => ['c', 'd'], 'maxHeight' => 100, 'follows' => ['container' => 'footer', 'gap' => null]],
            ],
        ], 2000, 2000);

        self::assertSame(['width' => 2000, 'height' => 2000], $scaled['canvas']);
        self::assertSame(40.0, $scaled['elements'][0]['gap']);
        self::assertSame(['container' => 'list', 'gap' => 30.0], $scaled['elements'][1]['follows']);
        self::assertSame(['container' => 'footer', 'gap' => null], $scaled['elements'][2]['follows'], 'a defaulted gap stays null — the compiler resolves it');
    }
}
