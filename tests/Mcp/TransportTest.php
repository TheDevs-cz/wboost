<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use WBoost\Web\Mcp\Transport\BufferStreamedResponse;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * Locks the FrankenPHP guard: `/_mcp` must never answer with a flushing
 * response, because under resident PHP that kills the NEXT request on the same
 * worker. See {@see \WBoost\Web\Mcp\Transport\BufferedMcpController}.
 *
 * The `/_mcp` requests below are made as a logged-in user: until Stage 1 adds
 * the `mcp` firewall the endpoint sits under `main`'s catch-all and an
 * anonymous POST is redirected to `/login`, never reaching the controller.
 * Swap this for token auth when S1-T3 lands.
 */
final class TransportTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2025-06-18';

    public function testInitializeIsNotStreamed(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        self::initialize($client);

        $response = $client->getResponse();

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"serverInfo"', (string) $response->getContent());
    }

    public function testToolsCallIsNotStreamed(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        self::initialize($client);

        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);

        self::jsonRpc($client, 'notifications/initialized', sessionId: $sessionId);

        self::jsonRpc($client, 'tools/call', [
            'name' => 'transport_probe',
            'arguments' => ['value' => 'buffered'],
        ], $sessionId);

        $response = $client->getResponse();

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        // Assert the call actually SUCCEEDED: a vanished test fixture would
        // otherwise silently degrade this into the "tool not found" error path,
        // which is a different branch of the transport.
        $content = (string) $response->getContent();
        self::assertStringNotContainsString('"error"', $content);
        self::assertStringContainsString('buffered', $content);
    }

    /**
     * The two cases above only cover today's happy path, where the SDK answers
     * `application/json` anyway. This one exercises the guard itself with a
     * response that really does stream — including the `ob_flush()`/`flush()`
     * pair the SDK's SSE loop uses, which is what a single output buffer would
     * fail to contain.
     */
    public function testStreamedResponseIsBufferedWithoutReachingTheOutput(): void
    {
        $streamed = new StreamedResponse(static function (): void {
            echo "event: message\ndata: {\"progress\":1}\n\n";
            @ob_flush();
            flush();
            echo "event: message\ndata: {\"progress\":2}\n\n";
        }, 207, [
            'Content-Type' => 'text/event-stream',
            'Mcp-Session-Id' => '0198f2ab-0000-7000-8000-000000000000',
        ]);
        $streamed->setProtocolVersion('1.0');

        $levelBefore = ob_get_level();
        ob_start();
        $buffered = (new BufferStreamedResponse())($streamed);
        $leaked = ob_get_clean();

        self::assertNotInstanceOf(StreamedResponse::class, $buffered);
        self::assertSame('', $leaked, 'The streamed body escaped to the output.');
        self::assertSame($levelBefore, ob_get_level(), 'Output buffer levels were left unbalanced.');
        self::assertSame(
            "event: message\ndata: {\"progress\":1}\n\nevent: message\ndata: {\"progress\":2}\n\n",
            $buffered->getContent(),
        );
        self::assertSame(207, $buffered->getStatusCode());
        self::assertSame('1.0', $buffered->getProtocolVersion());
        self::assertSame('text/event-stream', $buffered->headers->get('Content-Type'));
        self::assertSame('0198f2ab-0000-7000-8000-000000000000', $buffered->headers->get('Mcp-Session-Id'));
    }

    private static function initialize(KernelBrowser $client): void
    {
        self::jsonRpc($client, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function jsonRpc(
        KernelBrowser $client,
        string $method,
        array $params = [],
        null|string $sessionId = null,
    ): void {
        $payload = ['jsonrpc' => '2.0', 'method' => $method];

        // Notifications carry no id; requests do.
        if (!str_starts_with($method, 'notifications/')) {
            $payload['id'] = 1;
        }

        if ([] !== $params) {
            $payload['params'] = $params;
        }

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];

        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
            $server['HTTP_MCP_PROTOCOL_VERSION'] = self::PROTOCOL_VERSION;
        }

        $client->request(
            'POST',
            '/_mcp',
            server: $server,
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }
}
