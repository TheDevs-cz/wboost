<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Doctrine\DBAL\Connection;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\FileSource;

/**
 * Pictures a template made from an AI scan still owes (owner decision O11).
 *
 * At accept, a place for a picture the user left empty references the
 * project's stand-in row ({@see \WBoost\Web\Services\AiScan\AiScanStandIn::projectFile()},
 * `FileSource::ScanStandIn`). A canvas object whose `assetPath` — the storage
 * key the renderer inlines, so the one key that cannot go stale when the
 * picture is replaced in the editor — is still that row's file is therefore an
 * **unfilled picture**:
 *
 * - a decorative image ("Součást šablony") — unfilled until the designer
 *   replaces it in the editor; no export can supply it;
 * - a fillable slot — in practice the full-page background, which must carry a
 *   picture — unfilled only for an export that supplies no picture for it.
 *
 * An empty fillable place without a sample has no asset at all and is not
 * counted: it is a field the user fills at export.
 *
 * The rule per export path: the web asks first (the controllers answer a
 * "needs confirmation" result), the REST API and MCP refuse with
 * `unfilled_pictures` unless the caller passes `allowUnfilled`.
 */
final readonly class UnfilledPictures
{
    public const string CODE = 'unfilled_pictures';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * What the variant still owes, whatever an export supplies.
     *
     * @return array{fixed: int, fillable: list<string>} `fixed` — decorative
     *         places on the stand-in; `fillable` — input ids of fillable slots
     *         (the background) still showing it
     */
    public function inVariant(TemplateVariant $variant): array
    {
        $none = ['fixed' => 0, 'fillable' => []];

        if (!str_contains($variant->canvas, '"assetPath"')) {
            return $none;
        }

        $standInPaths = $this->standInPaths($variant->template->project->id->toString());

        if ($standInPaths === []) {
            return $none;
        }

        $canvas = json_decode($variant->canvas, true);
        $objects = is_array($canvas) && is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $found = $none;

        foreach ($objects as $object) {
            if (!is_array($object) || !in_array($object['assetPath'] ?? null, $standInPaths, true)) {
                continue;
            }

            $inputId = is_string($object['inputId'] ?? null) ? $object['inputId'] : '';
            $fillable = ($object['imagePlaceholder'] ?? false) === true && $inputId !== '';

            if ($fillable) {
                $found['fillable'][] = $inputId;
            } else {
                $found['fixed']++;
            }
        }

        return $found;
    }

    /** The number behind the pill `K doplnění: {n}` — everything on the stand-in. */
    public function count(TemplateVariant $variant): int
    {
        $found = $this->inVariant($variant);

        return $found['fixed'] + count($found['fillable']);
    }

    /**
     * How many stand-in pictures THIS export would show.
     *
     * @param array<array-key, mixed> $images the export's image values keyed
     *        by image input id, in any of the shapes the fill accepts (an id
     *        string, `{imageId, …}`, `{hide: true}`); a slot that is filled
     *        or hidden shows no stand-in
     */
    public function forExport(TemplateVariant $variant, array $images): int
    {
        $found = $this->inVariant($variant);
        $count = $found['fixed'];

        foreach ($found['fillable'] as $inputId) {
            if (!self::supplies($images[$inputId] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    private static function supplies(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }

        if (!is_array($value)) {
            return false;
        }

        $imageId = $value['imageId'] ?? null;
        $hide = $value['hide'] ?? false;

        return (is_string($imageId) && $imageId !== '') || in_array($hide, [true, 1, '1', 'true'], true);
    }

    /**
     * @return list<string>
     */
    private function standInPaths(string $projectId): array
    {
        /** @var list<string> $paths */
        $paths = $this->connection->fetchFirstColumn(
            'SELECT path FROM file_upload WHERE project_id = :project AND source = :source',
            ['project' => $projectId, 'source' => FileSource::ScanStandIn->value],
        );

        return $paths;
    }
}
