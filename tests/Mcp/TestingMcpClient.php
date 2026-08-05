<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Drives `/_mcp` over real HTTP the way a client does: JSON-RPC over POST,
 * `initialize` first, the `Mcp-Session-Id` echoed on every later call.
 *
 * The `mcp` firewall is STATELESS, so the personal access token has to ride on
 * every single request — there is no session to remember it. That is the whole
 * reason this helper exists rather than each test rolling its own request
 * builder: forgetting the header on the second call would 401 in a way that
 * looks like a protocol bug.
 */
readonly final class TestingMcpClient
{
    public const string PROTOCOL_VERSION = '2025-06-18';

    /**
     * `initialize` only — for tests that want to inspect that exact response.
     */
    public static function initialize(KernelBrowser $browser, null|string $token = null): void
    {
        self::request($browser, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
        ], token: $token);
    }

    /**
     * Full handshake (`initialize` → `notifications/initialized`), returning the
     * session id every subsequent call must carry.
     */
    public static function connect(KernelBrowser $browser, null|string $token = null): string
    {
        self::initialize($browser, $token);

        $sessionId = $browser->getResponse()->headers->get('Mcp-Session-Id');

        if ($sessionId === null) {
            throw new LogicException(sprintf(
                'MCP initialize did not return a session id (HTTP %d): %s',
                $browser->getResponse()->getStatusCode(),
                (string) $browser->getResponse()->getContent(),
            ));
        }

        self::request($browser, 'notifications/initialized', sessionId: $sessionId, token: $token);

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function request(
        KernelBrowser $browser,
        string $method,
        array $params = [],
        null|string $sessionId = null,
        null|string $token = null,
    ): void {
        $payload = ['jsonrpc' => '2.0', 'method' => $method];

        // Notifications carry no id; requests do.
        if (!str_starts_with($method, 'notifications/')) {
            $payload['id'] = 1;
        }

        if ([] !== $params) {
            $payload['params'] = $params;
        }

        $browser->request(
            'POST',
            '/_mcp',
            server: self::server($sessionId, $token),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function server(null|string $sessionId = null, null|string $token = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
            $server['HTTP_MCP_PROTOCOL_VERSION'] = self::PROTOCOL_VERSION;
        }

        return $server;
    }
}
