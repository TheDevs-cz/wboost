<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One trivial screenshot through the same Gotenberg, with a short time limit
 * of its own (the render client's is 20 s — far too long for a question).
 *
 * Gotenberg draws pages side by side: a page that hangs (measured 2026-11-14:
 * a tab in an endless loop) leaves a trivial one at ~0.5 s, while a renderer
 * that is down, restarting or has a full queue fails or times out here too.
 * So a `true` right after a render that timed out means the renderer works
 * and THAT PAGE was the problem.
 */
final readonly class RendererProbe implements RendererProbeInterface
{
    private const float TIMEOUT_SECONDS = 5.0;

    private const string PAGE = '<!DOCTYPE html><html><body><script>window.probeRendered = true;</script></body></html>';

    public function __construct(
        #[Autowire(service: 'gotenberg.client')]
        private HttpClientInterface $gotenberg,
    ) {
    }

    public function responds(): bool
    {
        $form = new FormDataPart([
            'files' => new DataPart(self::PAGE, 'index.html', 'text/html'),
            'waitForExpression' => 'window.probeRendered === true',
            'width' => '8',
            'height' => '8',
            'clip' => 'true',
            'format' => 'png',
        ]);

        try {
            $response = $this->gotenberg->request('POST', '/forms/chromium/screenshot/html', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS + 1,
            ]);

            return $response->getStatusCode() === 200 && $response->getContent(false) !== '';
        } catch (ExceptionInterface) {
            return false;
        }
    }
}
