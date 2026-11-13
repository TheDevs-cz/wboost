<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Value\Calendar\CalendarPictureSource;

/**
 * The pictures and fonts of a calendar as data URIs, for a page drawn inside
 * Gotenberg's Chromium (which has no route to the storage): the asset map of
 * the bootstrap and the `FontFace` list. Shared by the screenshot
 * ({@see CalendarPageRenderer}) and the PDF export ({@see CalendarPdfRenderer}).
 *
 * Only what the asked pages can show is inlined — everything the layout
 * refers to, every content picture that is not bound to a page (event icons)
 * and the per-page pictures of THOSE pages. The other assets keep their sizes
 * (the layout reads them) but carry no URL.
 */
final class CalendarRenderAssets
{
    private const array PER_PAGE_COLLECTIONS = ['pagePhotos', 'pageImages', 'pageQr', 'pageContent'];

    private const array ASSET_KEYS = ['asset', 'icon', 'head', 'bg'];

    private const int APPROVAL_JPEG_QUALITY = 82;

    /** @var array<string, null|string> font path → data URI (immutable per path; lives as long as the worker) */
    private array $fonts = [];

    public function __construct(
        private readonly CalendarDocument $document,
        private readonly CalendarAssetRepository $assetRepository,
        private readonly AssetInliner $assetInliner,
        private readonly GetFonts $getFonts,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private readonly FilesystemReader $filesystem,
    ) {
    }

    /**
     * Lower-case asset ids → true: what every page may show.
     *
     * @return array<string, true>
     */
    public function shared(Calendar $calendar): array
    {
        $ids = [];

        if ($calendar->layout !== null) {
            foreach (CalendarLayoutAssets::ids($calendar->layout, false) as $id) {
                $ids[strtolower($id)] = true;
            }
        }

        foreach ($calendar->content as $collection => $items) {
            if (!is_array($items) || $collection === 'specialPages' || in_array($collection, self::PER_PAGE_COLLECTIONS, true)) {
                continue;
            }

            foreach ($items as $item) {
                $ids += self::idsOf($item);
            }
        }

        return $ids;
    }

    /**
     * Page key → the lower-case asset ids only that page shows.
     *
     * @return array<string, array<string, true>>
     */
    public function perPage(Calendar $calendar): array
    {
        $pages = [];

        foreach ($calendar->content as $collection => $items) {
            if (!is_array($items)) {
                continue;
            }

            $special = $collection === 'specialPages';

            if (!$special && !in_array($collection, self::PER_PAGE_COLLECTIONS, true)) {
                continue;
            }

            foreach ($items as $id => $item) {
                $ids = self::idsOf($item);

                if ($ids !== []) {
                    $key = $special ? 'sp:' . $id : (string) $id;
                    $pages[$key] = ($pages[$key] ?? []) + $ids;
                }
            }
        }

        return $pages;
    }

    /**
     * The asset map of the bootstrap for these pages.
     *
     * @param list<string> $pageKeys
     * @param null|list<CalendarAsset> $assets the calendar's assets when the caller already has them
     * @param-out array<string, CalendarAsset> $deferred
     * @param null|array<string, CalendarAsset> $deferred when given, an uploaded raster is NOT read:
     *   its `url` is a token ({@see token()}) and the asset is put here under that token — the
     *   caller writes the document piece by piece and streams the file in ({@see writeDataUri()}),
     *   so a 10 MB photo never becomes a PHP string
     * @param null|\stdClass $layout the layout of an unsaved document — its pictures are inlined too (never deferred)
     */
    public function inlined(Calendar $calendar, array $pageKeys, CalendarPictureSource $source = CalendarPictureSource::Preview, null|array $assets = null, null|array &$deferred = null, null|\stdClass $layout = null): \stdClass
    {
        $defer = func_num_args() >= 5 && $layout === null;
        $deferred = [];

        $needed = $this->shared($calendar);

        // A document that is not the stored one (CalendarPageRenderer::renderDocument):
        // the pictures ITS layout names, on top of the stored layout's.
        if ($layout !== null) {
            foreach (CalendarLayoutAssets::ids($layout, false) as $id) {
                $needed[strtolower($id)] = true;
            }
        }

        $perPage = $this->perPage($calendar);

        foreach ($pageKeys as $pageKey) {
            $needed += $perPage[$pageKey] ?? [];
        }

        $map = [];

        foreach ($assets ?? $this->assetRepository->forCalendar($calendar->id) as $asset) {
            $info = $this->document->asset($asset);
            $id = $asset->id->toString();

            if (!isset($needed[strtolower($id)])) {
                $info['url'] = '';
            } elseif ($defer && $source === CalendarPictureSource::Original && $asset->width !== null) {
                $info['url'] = self::token($id);
                $deferred[$info['url']] = $asset;
            } else {
                $info['url'] = $this->dataUri($asset, $source) ?? '';
            }

            $info['previewUrl'] = null;
            $map[$id] = $info;
        }

        return (object) $map;
    }

    /**
     * The project faces the layout uses, as data URIs for `FontFace` objects.
     *
     * @return list<array{family: string, src: string}>
     */
    public function fontFaces(Calendar $calendar, \stdClass $design): array
    {
        $used = [];
        self::collectFamilies($design->layout ?? null, $used);

        $faces = [];
        $paths = [];

        foreach ($this->getFonts->allForProject($calendar->project->id) as $font) {
            $families = [];

            foreach ($font->faces as $face) {
                $families[$font->faceFamily($face)] = $face->filePath;
            }

            // The cover, back and special pages set their headings and the
            // bold / italic of a text in the SIBLING cuts of the fonts the
            // layout names (render/special.js `specialFamilies`), so every
            // cut of a used font travels.
            if (array_intersect_key($families, $used) !== []) {
                $used += array_fill_keys(array_keys($families), true);
            }

            $paths += $families;
        }

        foreach ($paths as $family => $path) {
            if (!isset($used[$family])) {
                continue;
            }

            if (!array_key_exists($path, $this->fonts)) {
                $this->fonts[$path] = $this->assetInliner->inlineFont($path);
            }

            if ($this->fonts[$path] !== null) {
                $faces[] = ['family' => $family, 'src' => $this->fonts[$path]];
            }
        }

        return $faces;
    }

    /** Stands for an asset's data URI in a document that is written in pieces. JSON- and HTML-safe. */
    public static function token(string $assetId): string
    {
        return '@@wbcal-asset:' . strtolower($assetId) . '@@';
    }

    /**
     * Writes the uploaded file as a `data:` URI into `$out`, base64-encoding
     * the storage stream on the way — constant memory whatever the file size.
     * Uploads are normalised when they are stored ({@see CalendarAssetUploader}),
     * so the bytes go out as they are: a JPEG lands in the PDF with its own
     * data, never re-encoded.
     *
     * @param resource $out
     *
     * @return bool false when the file is missing (nothing was written)
     */
    public function writeDataUri(CalendarAsset $asset, $out): bool
    {
        try {
            $in = $this->filesystem->readStream($asset->path);
        } catch (FilesystemException) {
            return false;
        }

        fwrite($out, sprintf('data:%s;base64,', $asset->mimeType));
        $filter = stream_filter_append($out, 'convert.base64-encode', STREAM_FILTER_WRITE);
        stream_copy_to_stream($in, $out);
        fclose($in);

        if (is_resource($filter)) {
            // removing the filter flushes its last (padded) group
            stream_filter_remove($filter);
        }

        return true;
    }

    private function dataUri(CalendarAsset $asset, CalendarPictureSource $source): null|string
    {
        if ($asset->width === null) {
            // an SVG: one file for every source (and its intrinsic size pinned)
            return $this->assetInliner->inlineImage($asset->path);
        }

        if ($source === CalendarPictureSource::Preview) {
            return $this->assetInliner->inlineImage($asset->previewPath ?? $asset->path);
        }

        try {
            $bytes = $this->filesystem->read($source === CalendarPictureSource::Original ? $asset->path : ($asset->previewPath ?? $asset->path));
        } catch (FilesystemException) {
            return null;
        }

        if ($source === CalendarPictureSource::PreviewJpeg) {
            $jpeg = self::opaqueAsJpeg($bytes);

            if ($jpeg !== null) {
                return 'data:image/jpeg;base64,' . base64_encode($jpeg);
            }
        }

        $mimeType = $source === CalendarPictureSource::Original || $asset->previewPath === null ? $asset->mimeType : 'image/webp';

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($bytes));
    }

    /**
     * A small raster re-encoded as JPEG — or null when it already is one,
     * carries transparency (a logo must keep it) or cannot be decoded.
     *
     * Only ever fed pictures of at most {@see CalendarAssetUploader::PREVIEW_LONG_EDGE}
     * px that the upload already vetted (its own preview derivative, or an
     * upload small enough to need none), so the decode is bounded.
     */
    private static function opaqueAsJpeg(string $bytes): null|string
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || max($size[0], $size[1]) > CalendarAssetUploader::PREVIEW_LONG_EDGE) {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);

            if ($image->getImageAlphaChannel() && $image->getImageChannelRange(\Imagick::CHANNEL_ALPHA)['minima'] < $image->getQuantum()) {
                $image->clear();

                return null;
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::APPROVAL_JPEG_QUALITY);
            $image->stripImage();
            $jpeg = $image->getImageBlob();
            $image->clear();

            return $jpeg;
        } catch (\ImagickException) {
            return null;
        }
    }

    /**
     * @return array<string, true>
     */
    private static function idsOf(mixed $item): array
    {
        $ids = [];

        if (is_array($item)) {
            foreach (self::ASSET_KEYS as $key) {
                if (is_string($item[$key] ?? null)) {
                    $ids[strtolower($item[$key])] = true;
                }
            }

            // the photo of a cover / special page: `pageContent[*].photo.asset`
            $photo = $item['photo'] ?? null;

            if (is_array($photo) && is_string($photo['asset'] ?? null)) {
                $ids[strtolower($photo['asset'])] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, true> $families
     */
    private static function collectFamilies(mixed $node, array &$families): void
    {
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                if ($key === 'family' && is_string($value) && $value !== '') {
                    $families[$value] = true;
                } else {
                    self::collectFamilies($value, $families);
                }
            }
        } elseif (is_array($node)) {
            foreach ($node as $value) {
                self::collectFamilies($value, $families);
            }
        }
    }
}
