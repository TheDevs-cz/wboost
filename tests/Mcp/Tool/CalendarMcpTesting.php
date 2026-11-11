<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * Drives the calendar tools end to end over `/_mcp` for the calendar tool
 * tests (the SDK derives a tool's input schema at compile time, so a tool can
 * be right in isolation and still fail to register).
 */
trait CalendarMcpTesting
{
    private null|KernelBrowser $browser = null;

    /**
     * A successful call, decoded.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function ok(string $tool, array $arguments, string $token = TestDataFixture::MCP_TOKEN_ACTIVE): array
    {
        $result = $this->call($tool, $arguments, $token);
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text']);

        return self::decode($result['content'][0]['text']);
    }

    /**
     * A refused call: the refusal's text.
     *
     * @param array<string, mixed> $arguments
     */
    private function refused(string $tool, array $arguments, string $token = TestDataFixture::MCP_TOKEN_ACTIVE): string
    {
        $result = $this->call($tool, $arguments, $token);
        self::assertTrue($result['isError'] ?? false, sprintf('%s was expected to be refused: %s', $tool, $result['content'][0]['text']));

        return $result['content'][0]['text'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError?: bool, content: list<array{text: string, type?: string, data?: string, mimeType?: string}>}
     */
    private function call(string $tool, array $arguments, string $token = TestDataFixture::MCP_TOKEN_ACTIVE): array
    {
        $response = $this->raw($tool, $arguments, $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $payload = self::decode((string) $response->getContent());
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());
        self::assertIsArray($payload['result']);

        /** @var array{isError?: bool, content: list<array{text: string, type?: string, data?: string, mimeType?: string}>} $result */
        $result = $payload['result'];

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function raw(string $tool, array $arguments, string $token): Response
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);
        // An empty argument map must travel as `{}` — `[]` is a JSON list.
        TestingMcpClient::request($browser, 'tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments], $sessionId, $token);

        return $browser->getResponse();
    }

    /**
     * The calendar as stored now — the identity map is cleared first, so this
     * is the database's answer and not the entity the request left behind.
     */
    private function calendar(string $calendarId): Calendar
    {
        $this->browser();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $calendar = $entityManager->find(Calendar::class, Uuid::fromString($calendarId));
        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * One value deep inside a decoded answer; null when the path ends early.
     */
    private static function dig(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A list of texts (notes, warnings) of an answer as one string.
     */
    private static function text(mixed $value): string
    {
        self::assertIsArray($value);

        return implode("\n", array_map(static fn (mixed $line): string => is_string($line) ? $line : '', $value));
    }

    private static function number(mixed $value): float
    {
        self::assertIsNumeric($value);

        return (float) $value;
    }
}
