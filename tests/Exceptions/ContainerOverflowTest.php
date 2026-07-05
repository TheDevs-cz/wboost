<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\ContainerOverflow;

/**
 * @covers \WBoost\Web\Exceptions\ContainerOverflow
 */
final class ContainerOverflowTest extends TestCase
{
    /**
     * The marker travels inside a real Gotenberg failOnConsoleExceptions error
     * body (409), which wraps the page's uncaught exception text — this shape
     * was captured from a live Gotenberg 8 run.
     */
    public function testParsesMarkerFromGotenbergErrorBody(): void
    {
        $body = 'Chromium failed to process the request: console exceptions:'
            . "\n" . 'Error: CONTAINER_OVERFLOW:{"containerId":"aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee","overflowPx":561.95}'
            . "\n" . '    at <anonymous>:1:20';

        $overflow = ContainerOverflow::tryFromGotenbergError($body);

        self::assertNotNull($overflow);
        self::assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $overflow->containerId);
        self::assertSame(561.95, $overflow->overflowPx);
    }

    public function testNonOverflowErrorsAreNotSwallowed(): void
    {
        self::assertNull(ContainerOverflow::tryFromGotenbergError('HTTP/1.1 409 Conflict returned for "http://gotenberg:3000/..."'));
        self::assertNull(ContainerOverflow::tryFromGotenbergError('Error: something entirely different'));
        self::assertNull(ContainerOverflow::tryFromGotenbergError(''));
    }

    public function testCorruptPayloadStillSignalsOverflow(): void
    {
        // The marker is present but the JSON got mangled — better a 400 with
        // no detail than a 500 masquerading as a render failure.
        $overflow = ContainerOverflow::tryFromGotenbergError('CONTAINER_OVERFLOW:{"containerId":');

        self::assertNotNull($overflow);
        self::assertNull($overflow->containerId);
        self::assertSame(0.0, $overflow->overflowPx);
    }
}
