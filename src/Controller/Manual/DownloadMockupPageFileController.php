<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Value\MockupPageDownload;

/**
 * Serves a file the admin attached to a mockup page — the whole page's file
 * (`slot=stranka`) or the one belonging to a single image slot (`slot=<index>`).
 *
 * Public, like the manual it hangs off: the manual preview needs no login, so
 * neither can the download button on it. The bytes go through PHP rather than
 * a direct storage link because the point is handing the reader the file back
 * under the name it was uploaded with, which only a Content-Disposition can do.
 */
final class DownloadMockupPageFileController extends AbstractController
{
    private const string PAGE_SLOT = 'stranka';

    public function __construct(
        readonly private Filesystem $filesystem,
    ) {
    }

    #[Route(
        path: '/stahnout-mockup/{pageId}/{slot}',
        name: 'download_mockup_page_file',
        requirements: ['slot' => 'stranka|\d+'],
    )]
    public function __invoke(
        #[MapEntity(id: 'pageId')]
        ManualMockupPage $page,
        string $slot,
    ): Response {
        $download = $slot === self::PAGE_SLOT
            ? $page->downloadFile
            : $page->imageDownload((int) $slot);

        if ($download === null) {
            throw $this->createNotFoundException('Mockup page has no file for this slot');
        }

        return $this->serve($download);
    }

    private function serve(MockupPageDownload $download): Response
    {
        try {
            // Buffered, never streamed: on resident FrankenPHP a flushing
            // StreamedResponse corrupts the next request on the worker.
            $contents = $this->filesystem->read($download->path);
        } catch (FilesystemException) {
            throw $this->createNotFoundException('Attached file is missing from storage');
        }

        return new Response($contents, headers: [
            'Content-Type' => $download->mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $download->fileName,
                self::asciiFallbackName($download->fileName),
            ),
        ]);
    }

    /**
     * makeDisposition() throws without a fallback as soon as the name carries
     * anything outside plain ASCII — "Vizitky_tisk.pdf" is the norm here — so
     * the diacritics are folded and everything the header cannot carry (the
     * quote, percent and slash it names explicitly included) is replaced.
     */
    private static function asciiFallbackName(string $fileName): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $fileName);
        $ascii = preg_replace('~[^A-Za-z0-9._ ()+-]~', '_', $ascii === false ? '' : $ascii) ?? '';

        return $ascii === '' ? 'soubor' : $ascii;
    }
}
