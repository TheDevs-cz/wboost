<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads font and image binaries from the upload filesystem and produces base64
 * data URIs. Used by the Gotenberg renderer so the export HTML is fully
 * self-contained — Gotenberg's headless Chromium then needs no network access
 * to Minio (whose public URL is not reachable from inside the Gotenberg
 * container).
 */
readonly final class AssetInliner
{
    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $filesystem,
    ) {
    }

    public function inlineFont(string $path): null|string
    {
        return $this->inline($path, $this->fontMimeType($path));
    }

    public function inlineImage(string $path): null|string
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return $this->inlineSvg($path);
        }

        return $this->inline($path, $this->imageMimeType($path));
    }

    /**
     * Like {@see inlineImage()} but also returns the raster image's natural
     * pixel dimensions (read from the same bytes, so the file is fetched once).
     * Returns null when the file is missing or not a dimension-bearing raster
     * image (e.g. SVG — unsupported as a placeholder fill in v1).
     *
     * @return null|array{dataUri: string, width: int, height: int}
     */
    public function inlineImageWithDimensions(string $path): null|array
    {
        try {
            $contents = $this->filesystem->read($path);
        } catch (FilesystemException) {
            return null;
        }

        $size = @getimagesizefromstring($contents);
        if ($size === false) {
            return null;
        }

        return [
            'dataUri' => sprintf('data:%s;base64,%s', $this->imageMimeType($path), base64_encode($contents)),
            'width' => $size[0],
            'height' => $size[1],
        ];
    }

    private function inlineSvg(string $path): null|string
    {
        try {
            $contents = $this->filesystem->read($path);
        } catch (FilesystemException) {
            return null;
        }

        return sprintf(
            'data:image/svg+xml;base64,%s',
            base64_encode(self::ensureSvgIntrinsicSize($contents)),
        );
    }

    /**
     * Design-tool SVGs (Illustrator, Figma, …) very often carry only a
     * `viewBox` and no explicit `width`/`height`. Loaded as an <img>-backed
     * Fabric image, such an SVG has no definite intrinsic size: the editor's
     * browser sizes it to the viewBox, but Gotenberg's headless Chromium
     * rasterizes it into the CSS default replaced box (~300×150). Fabric then
     * draws `ctx.drawImage(el, 0, 0, objWidth, objHeight, …)` with the object's
     * stored (viewBox-derived) dimensions as the source rectangle, sampling far
     * outside the small bitmap and clipping the SVG to its top-left corner —
     * the "SVG missing/malformed in export" bug.
     *
     * Injecting width/height from the viewBox onto the root <svg> makes the
     * intrinsic size deterministic and identical to what the editor measured.
     * SVGs that already declare a width or height (any unit) have a defined
     * intrinsic size and are left untouched; so are SVGs without a usable
     * viewBox (nothing to derive).
     */
    public static function ensureSvgIntrinsicSize(string $svg): string
    {
        return preg_replace_callback(
            '/<svg\b[^>]*>/i',
            static function (array $match): string {
                $tag = $match[0];

                // A declared width or height already pins the intrinsic size
                // (with the viewBox supplying the missing axis' ratio).
                // \s guards against matching `stroke-width` etc.
                if (preg_match('/\swidth\s*=/i', $tag) === 1 || preg_match('/\sheight\s*=/i', $tag) === 1) {
                    return $tag;
                }

                if (preg_match('/\sviewBox\s*=\s*(["\'])([^"\']*)\1/i', $tag, $viewBox) !== 1) {
                    return $tag;
                }

                $parts = preg_split('/[\s,]+/', trim($viewBox[2]));
                if ($parts === false || count($parts) !== 4) {
                    return $tag;
                }

                [, , $width, $height] = $parts;
                if (!is_numeric($width) || !is_numeric($height) || (float) $width <= 0 || (float) $height <= 0) {
                    return $tag;
                }

                return preg_replace(
                    '/^<svg\b/i',
                    sprintf('<svg width="%s" height="%s"', $width, $height),
                    $tag,
                    1,
                ) ?? $tag;
            },
            $svg,
            1,
        ) ?? $svg;
    }

    private function inline(string $path, string $mimeType): null|string
    {
        try {
            $contents = $this->filesystem->read($path);
        } catch (FilesystemException) {
            return null;
        }

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
    }

    private function fontMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            default => 'application/octet-stream',
        };
    }

    private function imageMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
