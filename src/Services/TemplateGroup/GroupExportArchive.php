<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateGroup;

use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Template\TemplateExportFileNames;
use WBoost\Web\Value\ExportFormat;
use ZipArchive;

/**
 * ZIP packing of a group export, shared by the web fill page and the API
 * group export. Names come from {@see TemplateExportFileNames}, so the ZIP
 * entry of a dimension (`{group} - {dimension}.png`) is exactly what its
 * single-dimension download is called; the ZIP itself is `{group}.zip`, the
 * print PDF one `{group} - PDF.zip` with `.pdf` entries.
 */
final readonly class GroupExportArchive
{
    public function __construct(
        private TemplateExportFileNames $fileNames,
    ) {
    }

    /**
     * Title embedded in a member's print PDF.
     */
    public function documentTitle(TemplateGroup $group, TemplateVariant $variant): string
    {
        return sprintf('%s – %s', $group->name, $variant->dimension->label());
    }

    /**
     * @param list<array{TemplateVariant, string}> $renders member variant + its file bytes, in archive order
     */
    public function build(TemplateGroup $group, array $renders, ExportFormat $format = ExportFormat::Png): string
    {
        /** @var array<string, string> $files filename → file bytes */
        $files = [];

        foreach ($renders as [$variant, $bytes]) {
            $name = $this->fileNames->groupMember($group, $variant, $format);
            $fileName = $name->name;

            // Two variants may share a dimension (e.g. two identical custom
            // sizes) — number instead of silently overwriting a ZIP entry.
            for ($counter = 2; isset($files[$fileName]); $counter++) {
                $fileName = $name->numbered($counter)->name;
            }

            $files[$fileName] = $bytes;
        }

        return $this->zip($files);
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'group-export-');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the ZIP export.');
        }

        try {
            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the ZIP archive for writing.');
            }

            foreach ($files as $fileName => $bytes) {
                // UTF-8 flag set explicitly: Czech names unpack intact.
                $zip->addFromString($fileName, $bytes, ZipArchive::FL_ENC_UTF_8);
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
