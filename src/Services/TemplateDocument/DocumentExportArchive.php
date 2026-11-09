<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Services\Template\TemplateExportFileNames;
use WBoost\Web\Value\ExportFormat;
use ZipArchive;

/**
 * Packing of a document export, shared by the web fill page, the API and MCP.
 * File names come from {@see TemplateExportFileNames}: `{document}.pdf` for
 * both PDFs, `{document}.zip` holding `{document} - {nn} - {page}.png` —
 * order-prefixed so a file browser (or an Instagram carousel upload) keeps
 * the page order.
 */
final readonly class DocumentExportArchive
{
    public function __construct(
        private TemplateExportFileNames $fileNames,
    ) {
    }

    public function contentType(ExportFormat $format): string
    {
        return $format === ExportFormat::Png ? 'application/zip' : $format->contentType();
    }

    /**
     * @param list<array{TemplateDocumentPage, string}> $renders page + its PNG, in page order
     */
    public function zip(TemplateDocument $document, array $renders): string
    {
        $path = tempnam(sys_get_temp_dir(), 'document-export-');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the ZIP export.');
        }

        try {
            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the ZIP archive for writing.');
            }

            foreach ($renders as $index => [$page, $bytes]) {
                // UTF-8 flag set explicitly: Czech page names unpack intact.
                $zip->addFromString($this->fileNames->documentPage($document, $page, $index + 1, count($renders))->name, $bytes, ZipArchive::FL_ENC_UTF_8);
            }

            $zip->close();

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new \RuntimeException('Could not read the finished ZIP archive.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }
}
