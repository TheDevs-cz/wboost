<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Services\Slugify;
use WBoost\Web\Value\ExportFormat;
use ZipArchive;

/**
 * File naming of a document export, shared by the web fill page, the API and
 * MCP: `{document}.pdf` for both PDFs, `{document}.zip` holding
 * `{nn}-{page}.png` — order-prefixed so a file browser (or an Instagram
 * carousel upload) keeps the page order.
 */
final class DocumentExportArchive
{
    public function fileName(TemplateDocument $document, ExportFormat $format): string
    {
        return $this->slug($document->name, 'dokument') . '.' . ($format === ExportFormat::Png ? 'zip' : $format->extension());
    }

    public function contentType(ExportFormat $format): string
    {
        return $format === ExportFormat::Png ? 'application/zip' : $format->contentType();
    }

    /**
     * @param list<array{TemplateDocumentPage, string}> $renders page + its PNG, in page order
     */
    public function zip(array $renders): string
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

            $digits = max(2, strlen((string) count($renders)));

            foreach ($renders as $index => [$page, $bytes]) {
                $zip->addFromString(
                    sprintf('%0' . $digits . 'd-%s.png', $index + 1, $this->slug($page->template->name, 'strana')),
                    $bytes,
                );
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

    private function slug(string $value, string $fallback): string
    {
        $slug = Slugify::string($value);

        return $slug !== '' ? $slug : $fallback;
    }
}
