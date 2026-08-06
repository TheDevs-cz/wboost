<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The mirror of {@see CompilationContext}: what {@see DesignDecompiler} needs
 * to know about a project to read an EXISTING canvas, gathered up front so the
 * decompile itself is a pure function.
 *
 * There is exactly one thing it cannot work out on its own. A canvas image
 * object carries a storage `assetPath` (and, since the assetId stamping, often
 * an `assetId`); the DSL addresses pictures by **gallery id only**. So the
 * decompiler needs `path → gallery row`, and it needs the row's NATURAL SIZE
 * as well — that is what lets it prove whether a stored background layer still
 * is the canonical cover fit {@see \WBoost\Web\Services\Editor\BackgroundLayer}
 * would rebuild.
 *
 * ## Paths that resolve to nothing are the interesting case
 *
 * Not every picture on a canvas is a gallery picture. A background uploaded
 * through the add/edit-variant form lands under `custom-templates/{variantId}/…`
 * and has no `file_upload` row at all — production canvases are full of them.
 * Those resolve to null here and the decompiler reports
 * {@see DesignLossCode::AssetUnresolved}, because the honest answer is *"the
 * DSL cannot name this picture; saving would blank it"*, not a fabricated id.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class DecompilationContext
{
    /**
     * @param array<string, DesignAsset> $assetsByPath gallery pictures keyed by
     *        their storage path — the string canvas objects carry as
     *        `assetPath`. Only the ones a given canvas references need be here.
     * @param array<string, DesignAsset> $assetsByUrl the same pictures keyed by
     *        their PUBLIC URL, because `assetPath` is a shortcut and `src` is
     *        the pointer that has always been there. Editors older than the
     *        assetPath stamping wrote image placeholders with a `src` and
     *        nothing else, and `AssetInliner` resolves those at render time —
     *        so a decompiler that only knew paths would call a perfectly
     *        ordinary gallery picture unnameable.
     */
    public function __construct(
        public array $assetsByPath,
        public array $assetsByUrl = [],
    ) {
    }

    /**
     * Nothing is resolvable. Legitimate for a canvas with no pictures; for one
     * with pictures it means every `asset` comes out null and every picture is
     * reported unresolved, which is the honest outcome of asking without
     * looking anything up.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    public function byPath(null|string $path): null|DesignAsset
    {
        if ($path === null || $path === '') {
            return null;
        }

        return $this->assetsByPath[$path] ?? null;
    }

    public function byUrl(null|string $url): null|DesignAsset
    {
        if ($url === null || $url === '') {
            return null;
        }

        return $this->assetsByUrl[$url] ?? null;
    }

    /**
     * The picture a canvas image object shows: by storage path first, by
     * public URL second.
     */
    public function forObject(null|string $assetPath, null|string $src): null|DesignAsset
    {
        return $this->byPath($assetPath) ?? $this->byUrl($src);
    }
}
