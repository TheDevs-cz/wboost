<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Image;

use WBoost\Web\Entity\FileUpload;

/**
 * The caption every gallery tile carries — in the gallery itself (modal,
 * standalone, Koš) and in the fill pages' image pickers, which show the same
 * tile (`_gallery_asset_caption.html.twig`).
 *
 * `name` is null for uploads from before the original name was recorded
 * (there is nothing to backfill it from — the tile says "Bez názvu");
 * `sizeLabel` is null while the pixel size is unknown (an unreadable object
 * the backfill could not size).
 */
readonly final class GalleryImageCaption
{
    /**
     * @return array{name: null|string, nameBase: string, nameExt: string, width: null|int, height: null|int, sizeLabel: null|string, format: string, tooltip: string}
     */
    public function describe(FileUpload $file): array
    {
        $name = $file->originalName;
        $nameBase = $name ?? '';
        $nameExt = '';

        if ($name !== null) {
            $dot = mb_strrpos($name, '.');

            // "archive.tar.gz" splits as "archive.tar" + ".gz"; a leading dot
            // (".hidden") or no dot at all keeps the whole name as the base.
            if ($dot !== false && $dot > 0) {
                $nameBase = mb_substr($name, 0, $dot);
                $nameExt = mb_substr($name, $dot);
            }
        }

        $sizeLabel = match (true) {
            $file->isSvg() => 'Vektor',
            $file->hasPixelSize() => sprintf('%d × %d px', $file->width, $file->height),
            default => null,
        };

        $format = strtoupper($file->extension());
        if ($format === 'JPEG') {
            $format = 'JPG';
        }

        $tooltipParts = array_filter([
            $name ?? 'Bez názvu',
            $sizeLabel !== null ? $sizeLabel . ' · ' . $format : $format,
        ]);

        return [
            'name' => $name,
            'nameBase' => $nameBase,
            'nameExt' => $nameExt,
            'width' => $file->width,
            'height' => $file->height,
            'sizeLabel' => $sizeLabel,
            'format' => $format,
            'tooltip' => implode(' · ', $tooltipParts),
        ];
    }
}
