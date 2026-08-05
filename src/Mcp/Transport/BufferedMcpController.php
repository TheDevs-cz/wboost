<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Transport;

use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Decorates the MCP bundle's HTTP controller so `/_mcp` can NEVER answer with a
 * flushing response. **Do not "optimize" this back into a stream.**
 *
 * Why
 * ---
 * We run on FrankenPHP, i.e. resident PHP (worker mode in production). A
 * response that commits its output early poisons the worker: the NEXT request
 * served by that same worker dies with "headers already sent
 * (`Response.php:393`)" — a stranger's request, not the one that streamed. The
 * same failure mode is why
 * {@see \WBoost\Web\Services\Editor\TemplateVariantImageRenderer} deliberately
 * buffers its Gotenberg bytes instead of streaming them.
 *
 * Why a decorator
 * ---------------
 * There is no configuration switch. {@see McpController::handle()} hard-codes
 * its transport and returns
 * `$httpFoundationFactory->createResponse($psr, $streamed)` with
 * `$streamed = ('text/event-stream' === Content-Type)`, so decorating
 * `mcp.server.controller` (wired in `config/packages/mcp.php`) is the only
 * place the decision can be overruled.
 *
 * When it fires
 * -------------
 * The SDK's `Mcp\Server\Transport\StreamableHttpTransport` only produces
 * `text/event-stream` when a handler suspended a Fiber to send the client
 * something mid-call (progress notifications, sampling, elicitation); plain
 * tool handlers always answer `application/json` and pass through here
 * untouched. There is no long-lived GET stream either — the transport answers
 * `GET /_mcp` with a 405. So this guard is dormant today and arms itself the
 * moment a tool starts reporting progress.
 *
 * Buffer, not refuse
 * ------------------
 * An `text/event-stream` body is drained by {@see BufferStreamedResponse} and
 * returned as a plain {@see Response} with its headers intact. The client still
 * receives valid SSE framing — just all at once, at the end. That keeps the
 * protocol legal and keeps a progress-reporting tool working (it loses only the
 * incrementality, which is the trade we accept); refusing outright would turn
 * such a tool into a hard error.
 *
 * Known limitation: server→client *requests* (sampling / elicitation) cannot
 * work behind this guard. The transport's loop polls for the client's answer,
 * which the client cannot send before it has seen a request we are still
 * buffering — so it would simply block until the SDK's per-request timeout.
 * Only one-way notifications are compatible with a buffered transport. That is
 * a deliberate scope limit, not an oversight.
 */
readonly final class BufferedMcpController
{
    public function __construct(
        private McpController $inner,
        private BufferStreamedResponse $buffer,
    ) {
    }

    public function handle(Request $request): Response
    {
        $response = $this->inner->handle($request);

        if (!$response instanceof StreamedResponse) {
            return $response;
        }

        return ($this->buffer)($response);
    }
}
