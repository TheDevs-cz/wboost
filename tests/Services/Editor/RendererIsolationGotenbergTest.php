<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * The renderer's second layer (docs/architecture/templates-render.md,
 * "Renderer isolation"): a script that DID get into a render page finds
 * nothing to talk to. The page asks for the addresses the 2026-11-13 proof of
 * concept reached — the renderer's own API on the loopback, the renderer by
 * its service name (a private address, like MinIO and the app beside it) and
 * an outside host — and reports what answered through the console-exception
 * channel (Gotenberg answers 409 with the text).
 *
 * It fails against a Gotenberg started without the restriction flags
 * (compose.yaml, the CI service in .github/workflows/test.yml and production's
 * compose carry the same ones): the loopback API always answers there.
 *
 * `gotenberg` group: needs a renderer; the page needs nothing else.
 */
#[Group('gotenberg')]
final class RendererIsolationGotenbergTest extends KernelTestCase
{
    public function testARenderPageReachesNoPrivateAddressAndNoOutsideHost(): void
    {
        self::bootKernel();

        $dsn = $_SERVER['GOTENBERG_DSN'] ?? $_ENV['GOTENBERG_DSN'] ?? null;
        self::assertIsString($dsn);

        $targets = [
            'loopback' => 'http://127.0.0.1:3000/health',
            'localhost' => 'http://localhost:3000/health',
            'loopbackV6' => 'http://[::1]:3000/health',
            // the renderer under its own service name: a private address of the same network as MinIO and the app
            'serviceName' => rtrim($dsn, '/') . '/health',
            'outside' => 'https://example.com/',
            'outsidePlain' => 'http://example.com/',
            // the control: the channel itself works, an embedded resource loads
            'embedded' => 'data:text/plain,ok',
        ];

        $page = sprintf(
            <<<'HTML'
                <!DOCTYPE html><html><body><script>
                (async () => {
                    const targets = %s;
                    const reached = {};
                    await Promise.all(Object.entries(targets).map(async ([name, url]) => {
                        try { await fetch(url, {mode: 'no-cors'}); reached[name] = true; } catch (error) { reached[name] = false; }
                    }));
                    setTimeout(() => { throw new Error('REACHED:' + JSON.stringify(reached) + ':END'); }, 0);
                    setTimeout(() => { window.isolationProbed = true; }, 50);
                })();
                </script></body></html>
                HTML,
            json_encode($targets, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP),
        );

        $form = new FormDataPart([
            'files' => new DataPart($page, 'index.html', 'text/html'),
            'waitForExpression' => 'window.isolationProbed === true',
            'failOnConsoleExceptions' => 'true',
            'width' => '8',
            'height' => '8',
            'clip' => 'true',
            'format' => 'png',
        ]);

        $response = self::getContainer()->get('gotenberg.client')->request('POST', '/forms/chromium/screenshot/html', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);

        $body = $response->getContent(false);
        self::assertSame(409, $response->getStatusCode(), 'the page reports through a console exception: ' . mb_substr($body, 0, 300));
        self::assertSame(1, preg_match('/REACHED:(\{.*?\}):END/', $body, $match), mb_substr($body, 0, 300));

        $reached = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($reached);

        self::assertTrue($reached['embedded'] ?? null, 'the control: a data: URL loads, so "not reached" below means refused');

        foreach (['loopback', 'localhost', 'loopbackV6', 'serviceName', 'outside', 'outsidePlain'] as $name) {
            self::assertFalse($reached[$name] ?? null, sprintf('the render page reached "%s" (%s) — the renderer runs without its restriction flags', $name, $targets[$name]));
        }
    }
}
