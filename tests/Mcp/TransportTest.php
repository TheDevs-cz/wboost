<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use WBoost\Web\Mcp\Transport\BufferStreamedResponse;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Locks the FrankenPHP guard: `/_mcp` must never answer with a flushing
 * response, because under resident PHP that kills the NEXT request on the same
 * worker. See {@see \WBoost\Web\Mcp\Transport\BufferedMcpController}.
 *
 * The `/_mcp` requests below authenticate with the MCP personal access token
 * fixture (S1-T3 replaced the interim session login). The `mcp` firewall is
 * stateless, so the header rides on every request — {@see TestingMcpClient}
 * takes care of that. Authentication itself is covered by {@see AuthTest};
 * here it is only the price of admission.
 */
final class TransportTest extends WebTestCase
{
    public function testInitializeIsNotStreamed(): void
    {
        $client = self::createClient();

        TestingMcpClient::initialize($client, TestDataFixture::MCP_TOKEN_ACTIVE);

        $response = $client->getResponse();

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"serverInfo"', (string) $response->getContent());
    }

    public function testToolsCallIsNotStreamed(): void
    {
        $client = self::createClient();

        $sessionId = TestingMcpClient::connect($client, TestDataFixture::MCP_TOKEN_ACTIVE);

        TestingMcpClient::request($client, 'tools/call', [
            'name' => 'transport_probe',
            'arguments' => ['value' => 'buffered'],
        ], $sessionId, TestDataFixture::MCP_TOKEN_ACTIVE);

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
}
