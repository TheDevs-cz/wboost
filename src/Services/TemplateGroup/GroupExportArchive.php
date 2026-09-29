<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateGroup;

use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Slugify;
use ZipArchive;

/**
 * File naming + ZIP packing of a group export, shared by the web fill page
 * (ZIP + per-dimension PNG) and the API group export so both hand the user
 * identically named files: `{group}.zip` holding `{group}-{dimension}.png`
 * entries, and the same `{group}-{dimension}.png` for a single dimension.
 */
final class GroupExportArchive
{
    public function archiveName(TemplateGroup $group): string
    {
        return $this->groupSlug($group) . '.zip';
    }

    public function fileName(TemplateGroup $group, TemplateVariant $variant): string
    {
        return sprintf('%s-%s.png', $this->groupSlug($group), $this->nonEmptySlug($variant->dimension->label(), 'varianta'));
    }

    /**
     * @param list<array{TemplateVariant, string}> $renders member variant + its PNG bytes, in archive order
     */
    public function build(TemplateGroup $group, array $renders): string
    {
        /** @var array<string, string> $files filename → PNG bytes */
        $files = [];

        foreach ($renders as [$variant, $bytes]) {
            $fileName = $this->fileName($group, $variant);
            $baseName = substr($fileName, 0, -4);

            // Two variants may share a dimension (e.g. two identical custom
            // sizes) — suffix instead of silently overwriting a ZIP entry.
            for ($suffix = 2; isset($files[$fileName]); $suffix++) {
                $fileName = "$baseName-$suffix.png";
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
                $zip->addFromString($fileName, $bytes);
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

    private function groupSlug(TemplateGroup $group): string
    {
        return $this->nonEmptySlug($group->name, 'export');
    }

    private function nonEmptySlug(string $value, string $fallback): string
    {
        $slug = Slugify::string($value);

        return $slug !== '' ? $slug : $fallback;
    }
}
