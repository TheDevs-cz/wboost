<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Transport;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs a {@see StreamedResponse}'s callback with its output captured and hands
 * back an ordinary, fully-buffered {@see Response} carrying the same body,
 * status, headers and protocol version. Not one byte reaches the SAPI.
 *
 * Used by {@see BufferedMcpController} — the FrankenPHP guard on `/_mcp`; that
 * class explains WHY nothing here may ever stream.
 *
 * **The double output buffer is load-bearing.** The SSE loop inside the MCP
 * SDK's `Mcp\Server\Transport\StreamableHttpTransport` writes with `echo`
 * followed by `@ob_flush(); flush();`. Under a single buffer that `ob_flush()`
 * would push the bytes straight past us to the wire — committing output early,
 * which is exactly the corruption the guard exists to prevent. Two nested
 * buffers contain it: the inner one absorbs the `echo`s, its `ob_flush()` lands
 * in the outer one instead of at the SAPI, and `flush()` becomes a no-op
 * because nothing has reached the SAPI yet. Bytes already flushed therefore
 * precede whatever is left unflushed, and the captures are concatenated in that
 * order.
 */
readonly final class BufferStreamedResponse
{
    public function __invoke(StreamedResponse $response): Response
    {
        $buffered = new Response(
            $this->drain($response),
            $response->getStatusCode(),
        );

        // Carry the original bag over wholesale rather than re-adding headers:
        // it keeps Content-Type (`text/event-stream` stays truthful — the
        // framing is valid SSE, just delivered in one go), the `Mcp-Session-Id`
        // the client needs for its next call, and any cookies as objects.
        $buffered->headers = $response->headers;
        $buffered->setProtocolVersion($response->getProtocolVersion());

        return $buffered;
    }

    private function drain(StreamedResponse $response): string
    {
        $baseLevel = ob_get_level();

        ob_start();
        ob_start();

        try {
            $response->sendContent();
        } finally {
            $body = '';

            // Innermost first: whatever is still unflushed comes AFTER what the
            // callback already ob_flush()ed down into the level below it.
            // ob_get_clean() only returns false with no buffer active, which
            // the loop condition rules out.
            while (ob_get_level() > $baseLevel) {
                $body = ob_get_clean() . $body;
            }
        }

        return $body;
    }
}
