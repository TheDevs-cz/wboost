<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Http;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twig\Environment;
use WBoost\Web\Services\Http\RequestBodyLimit;

/**
 * PHP empties $_POST and $_FILES for a body past `post_max_size` and runs the
 * request anyway; the listener turns that into an honest 413 before the
 * CSRF check / form ever sees the empty request.
 */
final class RejectOversizedRequestBodyListenerTest extends WebTestCase
{
    private KernelBrowser $client;

    private int $maxBytes;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->maxBytes = RequestBodyLimit::postMaxSizeBytes();

        if ($this->maxBytes === 0) {
            self::markTestSkipped('post_max_size is unlimited in this PHP.');
        }
    }

    public function testAnApiClientGetsAJson413(): void
    {
        $this->client->request('POST', '/api/token', server: [
            'CONTENT_LENGTH' => (string) ($this->maxBytes + 1),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseStatusCodeSame(413);
        self::assertSame([
            'status' => 'error',
            'code' => 'request_too_large',
            'message' => RequestBodyLimit::tooLargeMessage($this->maxBytes),
            'maxBytes' => $this->maxBytes,
        ], $this->json());
    }

    /**
     * No Accept header at all — the path alone marks a program caller.
     */
    public function testTheScannerApiGetsJsonByPath(): void
    {
        $this->client->request('POST', '/internal/scanner/00000000-0000-0000-0000-000000000000/finish', server: [
            'CONTENT_LENGTH' => (string) ($this->maxBytes + 1),
        ]);

        self::assertResponseStatusCodeSame(413);
        self::assertSame('request_too_large', $this->json()['code'] ?? null);
    }

    public function testALiveComponentRequestGetsJson(): void
    {
        $this->client->request('POST', '/_components/SomeComponent/save', server: [
            'CONTENT_LENGTH' => (string) ($this->maxBytes + 1),
            'HTTP_ACCEPT' => 'application/vnd.live-component+html',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(413);
        self::assertSame('request_too_large', $this->json()['code'] ?? null);
    }

    public function testABrowserFormPostGetsAnHtml413(): void
    {
        $this->client->request('POST', '/login', ['_username' => 'x'], server: [
            'CONTENT_LENGTH' => (string) ($this->maxBytes + 1),
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ]);

        self::assertResponseStatusCodeSame(413);
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(
            sprintf('max. %d MB najednou', RequestBodyLimit::megabytes($this->maxBytes)),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testABodyWithinTheLimitPassesUntouched(): void
    {
        $this->client->request('POST', '/api/token', ['grant_type' => 'client_credentials'], server: [
            'CONTENT_LENGTH' => (string) $this->maxBytes,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertNotSame(413, $this->client->getResponse()->getStatusCode());
    }

    public function testAGetIsNeverRejected(): void
    {
        $this->client->request('GET', '/login', server: [
            'CONTENT_LENGTH' => (string) ($this->maxBytes + 1),
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * The test kernel runs with debug on, so the request above gets Symfony's
     * exception page; this renders the production error page itself.
     */
    public function testTheErrorPageShowsTheCzechMessage(): void
    {
        $message = RequestBodyLimit::tooLargeMessage($this->maxBytes);
        $twig = self::getContainer()->get(Environment::class);
        self::getContainer()->get(RequestStack::class)->push(Request::create('/login', 'POST'));

        $html = $twig->render('bundles/TwigBundle/Exception/error.html.twig', [
            'exception' => FlattenException::createFromThrowable(new HttpException(413, $message)),
            'status_code' => 413,
            'status_text' => 'Content Too Large',
        ]);

        self::assertStringContainsString('Příliš velký požadavek', $html);
        self::assertStringContainsString(htmlspecialchars($message), $html);
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
