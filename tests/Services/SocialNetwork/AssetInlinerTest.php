<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\SocialNetwork\AssetInliner;

final class AssetInlinerTest extends TestCase
{
    public function testInjectsWidthAndHeightFromViewBoxWhenBothMissing(): void
    {
        $svg = '<svg id="Vrstva_1" xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 919.7 166.8"><path d="M0 0"/></svg>';

        $result = AssetInliner::ensureSvgIntrinsicSize($svg);

        self::assertStringContainsString('width="919.7"', $result);
        self::assertStringContainsString('height="166.8"', $result);
        // The rest of the document is untouched.
        self::assertStringContainsString('viewBox="0 0 919.7 166.8"', $result);
        self::assertStringContainsString('<path d="M0 0"/>', $result);
    }

    public function testLeavesSvgWithExplicitWidthUntouched(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="46.2748mm" viewBox="0 0 100 200"><path/></svg>';

        self::assertSame($svg, AssetInliner::ensureSvgIntrinsicSize($svg));
    }

    public function testLeavesSvgWithExplicitHeightUntouched(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" height="200" viewBox="0 0 100 200"><path/></svg>';

        self::assertSame($svg, AssetInliner::ensureSvgIntrinsicSize($svg));
    }

    public function testLeavesSvgWithoutViewBoxUntouched(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path/></svg>';

        self::assertSame($svg, AssetInliner::ensureSvgIntrinsicSize($svg));
    }

    public function testDoesNotMistakeStrokeWidthForWidth(): void
    {
        // `stroke-width` on the root must NOT count as a declared width — the
        // \s guard in the pattern prevents the false positive.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" stroke-width="2" viewBox="0 0 300 150"><path/></svg>';

        $result = AssetInliner::ensureSvgIntrinsicSize($svg);

        self::assertStringContainsString('width="300"', $result);
        self::assertStringContainsString('height="150"', $result);
    }

    public function testHandlesCommaSeparatedViewBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0,0,640,480"><path/></svg>';

        $result = AssetInliner::ensureSvgIntrinsicSize($svg);

        self::assertStringContainsString('width="640"', $result);
        self::assertStringContainsString('height="480"', $result);
    }

    public function testIgnoresDegenerateViewBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0"><path/></svg>';

        self::assertSame($svg, AssetInliner::ensureSvgIntrinsicSize($svg));
    }

    public function testOnlyTouchesRootSvgTag(): void
    {
        // A nested <svg> (e.g. a symbol) must be left alone.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><svg viewBox="0 0 10 10"><path/></svg></svg>';

        $result = AssetInliner::ensureSvgIntrinsicSize($svg);

        self::assertSame(1, substr_count($result, 'width="100"'));
        self::assertStringContainsString('<svg viewBox="0 0 10 10">', $result);
    }
}
