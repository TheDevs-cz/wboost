<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;

/**
 * Chunk PDFs → one PDF, through Gotenberg's merge route (`/forms/pdf/merge`,
 * litho there): a purely structural merge — pages, fonts and pictures are
 * carried over as they are, nothing is re-encoded. The application image has
 * no PDF merger of its own (Ghostscript would re-interpret every page).
 *
 * The request is posted with the `gotenberg.client` directly (its time
 * limits apply): sensiolabs/gotenberg-bundle 1.x knows only Gotenberg 8's
 * `/forms/pdfengines/merge`, which Gotenberg 9 answers with a 404.
 *
 * Files travel as streams in both directions; PHP never holds one in memory.
 */
readonly final class CalendarPdfMerger
{
    /** Gotenberg answers these when it is busy or a request waited too long — retry later. */
    private const array OVERLOADED = [
        Response::HTTP_TOO_MANY_REQUESTS,
        Response::HTTP_SERVICE_UNAVAILABLE,
        Response::HTTP_GATEWAY_TIMEOUT,
    ];

    public function __construct(
        #[Autowire(service: 'gotenberg.client')]
        private HttpClientInterface $gotenberg,
    ) {
    }

    /**
     * @param list<string> $paths absolute paths; merged in THIS order
     * @param string $directory where the merged file is written
     * @param string $name its file name without the extension
     *
     * @return string absolute path of the merged file
     *
     * @throws TemplateRenderUnavailable
     */
    public function merge(array $paths, string $directory, string $name = 'merged'): string
    {
        $filesystem = new Filesystem();
        $target = $directory . '/' . $name . '.pdf';

        if (count($paths) === 1) {
            $filesystem->rename($paths[0], $target, true);

            return $target;
        }

        // Gotenberg merges in the alphanumerical order of the file names.
        $parts = [];
        $links = $directory . '/merge-in';
        $filesystem->mkdir($links);

        foreach ($paths as $index => $path) {
            $input = sprintf('%s/%05d.pdf', $links, $index);
            $filesystem->rename($path, $input, true);
            $parts[] = ['files' => DataPart::fromPath($input, basename($input), 'application/pdf')];
        }

        try {
            $form = new FormDataPart($parts);
            $response = $this->gotenberg->request('POST', '/forms/pdf/merge', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
            ]);
            $status = $response->getStatusCode();

            if ($status !== Response::HTTP_OK) {
                $body = $response->getContent(false);

                if (in_array($status, self::OVERLOADED, true)) {
                    throw TemplateRenderUnavailable::timedOut(new \RuntimeException(sprintf('Gotenberg answered %d: %s', $status, mb_substr($body, 0, 400))));
                }

                throw new \RuntimeException('The calendar PDF chunks could not be merged: ' . mb_substr($body, 0, 400));
            }

            $file = fopen($target, 'wb');

            if ($file === false) {
                throw new \RuntimeException('The merged calendar PDF cannot be written.');
            }

            try {
                foreach ($this->gotenberg->stream($response) as $chunk) {
                    if (fwrite($file, $chunk->getContent()) === false) {
                        throw new \RuntimeException('The merged calendar PDF could not be written (disk full?).');
                    }
                }
            } finally {
                fclose($file);
            }
        } catch (TransportExceptionInterface $exception) {
            throw TemplateRenderUnavailable::timedOut($exception);
        } finally {
            $filesystem->remove($links);
        }

        if (!is_file($target) || filesize($target) < 64) {
            throw new \RuntimeException('The merged calendar PDF came back empty.');
        }

        return $target;
    }
}
