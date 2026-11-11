<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Sensiolabs\GotenbergBundle\Processor\FileProcessor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;

/**
 * Chunk PDFs → one PDF, through Gotenberg's merge route (qpdf there): a
 * purely structural merge — pages, fonts and pictures are carried over as
 * they are, nothing is re-encoded. The application image has no PDF merger
 * of its own (Ghostscript would re-interpret every page).
 *
 * Files travel as streams in both directions; PHP never holds one in memory.
 */
readonly final class CalendarPdfMerger
{
    public function __construct(
        private GotenbergPdfInterface $gotenberg,
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
        $inputs = [];
        $links = $directory . '/merge-in';
        $filesystem->mkdir($links);

        foreach ($paths as $index => $path) {
            $input = sprintf('%s/%05d.pdf', $links, $index);
            $filesystem->rename($path, $input, true);
            $inputs[] = $input;
        }

        try {
            $this->gotenberg->merge()
                ->files(...$inputs)
                ->fileName($name)
                ->generate()
                ->processor(new FileProcessor($filesystem, $directory))
                ->process();
        } catch (TransportExceptionInterface $exception) {
            throw TemplateRenderUnavailable::timedOut($exception);
        } catch (ClientException $exception) {
            if (TemplateVariantImageRenderer::isRendererOverloaded($exception)) {
                throw TemplateRenderUnavailable::timedOut($exception);
            }

            throw new \RuntimeException('The calendar PDF chunks could not be merged: ' . mb_substr(CalendarPageRenderer::errorBody($exception), 0, 400), 0, $exception);
        } finally {
            $filesystem->remove($links);
        }

        if (!is_file($target) || filesize($target) < 64) {
            throw new \RuntimeException('The merged calendar PDF came back empty.');
        }

        return $target;
    }
}
