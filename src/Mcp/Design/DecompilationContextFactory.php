<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\UploaderHelper;

/**
 * The mirror of {@see CompilationContextFactory}: everything
 * {@see DesignDecompiler} needs to look up before it can read an EXISTING
 * canvas, which is exactly one thing — `storage path → gallery picture`.
 *
 * ## The direction is backwards, and that is the whole problem
 *
 * The DSL addresses pictures by **gallery id**. A canvas object addresses them
 * by `assetPath` (a storage key) and/or `src` (a public URL); nothing on it is
 * required to carry the id. So this class has to work out which gallery row a
 * stored path belongs to, and it does so the only way that is both cheap and
 * exact: a gallery upload is stored at `file-upload/{projectId}/{fileId}.{ext}`
 * (`UploadFileHandler`), so the file NAME is the `FileUpload` UUID. Newer
 * canvases also carry a stamped `assetId`, which is used when present.
 *
 * The candidate id is then RESOLVED — through
 * {@see CompilationContextFactory::assetsById()}, deliberately the very method
 * the compiler uses. A basename that parses as a UUID proves nothing on its
 * own: the row may be gone, trashed, or belong to another project, and in all
 * three cases the compiler would refuse the id. Sharing the resolver is what
 * makes "the decompiler can name it" and "the compiler will accept it" the same
 * statement, which is the property a `get_design` → `set_design` round trip
 * rests on.
 *
 * ## Paths that resolve to nothing are the point, not an edge case
 *
 * A background uploaded through the add/edit-variant form lands under
 * `custom-templates/{variantId}/background-*.png` and has **no `file_upload`
 * row at all** — S4-T5 found one in 1 of 5 sampled production canvases. Its
 * basename is not a UUID, nothing resolves, and the decompiler reports
 * {@see DesignLossCode::AssetUnresolved}. That is the honest answer, and
 * {@see DesignOverwriteGuard} is what stops it from becoming a silent deletion.
 */
readonly final class DecompilationContextFactory
{
    public function __construct(
        private CompilationContextFactory $compilationContextFactory,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    public function forVariant(TemplateVariant $variant): DecompilationContext
    {
        /** @var mixed $decoded */
        $decoded = $variant->canvas === '' ? [] : json_decode($variant->canvas, true);

        return $this->forCanvas($variant->template->project, is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<array-key, mixed> $canvas the decoded canvas JSON document
     */
    public function forCanvas(Project $project, array $canvas): DecompilationContext
    {
        $candidates = $this->candidates($canvas);

        if ($candidates === []) {
            return DecompilationContext::empty();
        }

        /** @var list<string> $ids */
        $ids = array_values(array_unique(array_map(
            static fn (array $candidate): string => $candidate['id'],
            $candidates,
        )));

        $resolved = $this->compilationContextFactory->assetsById($project, $ids);

        /** @var array<string, DesignAsset> $byPath */
        $byPath = [];
        /** @var array<string, DesignAsset> $byUrl */
        $byUrl = [];

        foreach ($candidates as $candidate) {
            $asset = $resolved[$candidate['id']] ?? null;

            if ($asset === null) {
                continue;
            }

            // The row's own path/url first — that is the authoritative pair —
            // and then the strings the OBJECT actually carries, which is what
            // the decompiler looks up. They are the same in every canvas this
            // app writes; keying both means a canvas hand-edited to a slightly
            // different spelling still resolves rather than silently reporting
            // the picture as unnameable.
            $byPath[$asset->path] = $asset;
            $byUrl[$asset->url] = $asset;

            if ($candidate['path'] !== null) {
                $byPath[$candidate['path']] = $asset;
            }

            if ($candidate['src'] !== null) {
                $byUrl[$candidate['src']] = $asset;
            }
        }

        return new DecompilationContext($byPath, $byUrl);
    }

    /**
     * One entry per canvas image object that COULD name a gallery picture:
     * whatever it points at, plus the id that pointer implies.
     *
     * Objects with no candidate id at all (the form-uploaded background above,
     * an inlined `data:` URI, an external URL) produce nothing — there is no id
     * to look up, and inventing one would resolve to nothing anyway while
     * hiding the reason.
     *
     * @param array<array-key, mixed> $canvas
     * @return list<array{id: string, path: null|string, src: null|string}>
     */
    private function candidates(array $canvas): array
    {
        /** @var mixed $objects */
        $objects = $canvas['objects'] ?? null;

        if (!is_array($objects)) {
            return [];
        }

        /** @var list<array{id: string, path: null|string, src: null|string}> $candidates */
        $candidates = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $path = self::string($object, 'assetPath');
            $src = self::string($object, 'src');

            // A `src` that this app produced is a public URL over a storage
            // key, so it names a path too. `AssetInliner` resolves exactly
            // these at render time — an editor older than the assetPath
            // stamping wrote placeholders with nothing else.
            $fromUrl = $src === null ? null : $this->uploaderHelper->getPathFromPublicUrl($src);

            $id = self::galleryId($object, $path ?? $fromUrl);

            if ($id === null) {
                continue;
            }

            $candidates[] = ['id' => $id, 'path' => $path, 'src' => $src];
        }

        return $candidates;
    }

    /**
     * The stamped `assetId` when the editor wrote one, else the storage key's
     * file name — which for `file-upload/{projectId}/{fileId}.{ext}` IS the
     * `FileUpload` UUID.
     *
     * @param array<array-key, mixed> $object
     */
    private static function galleryId(array $object, null|string $path): null|string
    {
        $stamped = self::string($object, 'assetId');

        if ($stamped !== null && Uuid::isValid($stamped)) {
            return $stamped;
        }

        if ($path === null) {
            return null;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);

        return Uuid::isValid($name) ? $name : null;
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function string(array $object, string $key): null|string
    {
        /** @var mixed $value */
        $value = $object[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
