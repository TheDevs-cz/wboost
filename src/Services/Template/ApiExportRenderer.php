<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Print\PrintPdfRenderer;
use WBoost\Web\Services\SocialNetwork\ResolveElementOffsets;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveShapeColorOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\ValidateContainerHides;
use WBoost\Web\Value\AppliedApiRows;
use WBoost\Web\Value\ExportFormat;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * The API's render core — every API render (variant preview + export, group
 * preview + ZIP + per-dimension export) runs through here, so they share ONE
 * strict policy: invalid values and container overflow FAIL (structured 400s,
 * see {@see \WBoost\Web\Api\Templates\ExportErrorResponse}) instead of being
 * truncated / shown, unlike the lenient web fill pages.
 *
 * Values arrive in the API wire shape (`inputs` / `images` maps keyed by input
 * UUID). A group fill passes the SAME maps to every member variant — group
 * members share their input ids, and both resolvers skip ids a variant does
 * not carry — plus per-dimension image `placements`.
 *
 * The user's element moves ("Uživatel může posunout") arrive as `offsets`
 * (element id → `{x, y}` canvas px of THAT variant — a group / document
 * caller passes the dimension's / page's own map) and are validated STRICTLY
 * by {@see ResolveElementOffsets} (`unknown_element`, `move_not_allowed`,
 * `offset_out_of_range`) against the EXPANDED variant, then baked by the
 * renderer.
 *
 * Repeatable container rows arrive as `rows` (row container id → list of
 * row objects keyed by the TEMPLATE row's member ids, the list length is the
 * count) and are folded into the value maps by {@see ApiRepeatableRows::apply()}
 * (strict: `not_repeatable`, `too_many_rows`, `too_few_rows`,
 * `unknown_row_input`, `ambiguous_row_value`) before anything else; a value
 * refused further down is reported with its `rowContainerId` + `rowIndex`.
 * A caller that needs the applied maps itself (to record the version, or
 * to layer a group dimension's placements over a row's picture) applies
 * them first and passes the {@see AppliedApiRows} as `$rows`; the value maps
 * it passes alongside are then taken as already applied.
 */
readonly final class ApiExportRenderer
{
    private const array TRANSFORM_FIELDS = ['scale', 'offsetX', 'offsetY', 'offsetXRatio', 'offsetYRatio', 'rotation'];

    public function __construct(
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveRichTextOptions $resolveRichTextOptions,
        private ResolveImageOverrides $resolveImageOverrides,
        private TemplateVariantImageRendererInterface $renderer,
        private ResolveShapeColorOverrides $resolveShapeColorOverrides,
        private PrintPdfRenderer $printPdfRenderer,
    ) {
    }

    /**
     * The exported FILE in either format: the PNG of {@see render()} or the
     * print PDF (strict like every API render). The caller checks that a PDF
     * is only asked of a print format.
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $shapeColors
     * @param array<array-key, mixed> $offsets element id → `{x, y}` (this variant's own map)
     * @param array<array-key, mixed>|AppliedApiRows $rows the body's `rows`, or already applied
     */
    public function renderExport(
        ExportFormat $exportFormat,
        TemplateVariant $variant,
        string $title,
        array $inputs,
        array $images,
        array $shapeColors = [],
        array $offsets = [],
        array|AppliedApiRows $rows = [],
    ): string {
        $variant = RepeatableRows::expand($variant);

        if ($exportFormat === ExportFormat::Png) {
            return $this->render($variant, $inputs, $images, shapeColors: $shapeColors, offsets: $offsets, rows: $rows);
        }

        if ($exportFormat !== ExportFormat::Pdf) {
            throw new \InvalidArgumentException(sprintf('A single variant cannot be exported as "%s".', $exportFormat->value));
        }

        [$overrides, $imageOverrides] = $this->resolve($variant, $inputs, $images, $shapeColors, $offsets, $rows);

        return $this->printPdfRenderer->render($variant, $overrides, $imageOverrides, $title, strictContainerOverflow: true);
    }

    /**
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $shapeColors shape inputId → `#rrggbb`
     * @param array<array-key, mixed> $offsets element id → `{x, y}`
     * @param array<array-key, mixed>|AppliedApiRows $rows the body's `rows`, or already applied
     *
     * @throws \WBoost\Web\Exceptions\InvalidRichTextValue
     * @throws InvalidExportValue
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable
     */
    public function render(
        TemplateVariant $variant,
        array $inputs,
        array $images,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $shapeColors = [],
        array $offsets = [],
        array|AppliedApiRows $rows = [],
    ): string {
        $variant = RepeatableRows::expand($variant);
        [$overrides, $imageOverrides] = $this->resolve($variant, $inputs, $images, $shapeColors, $offsets, $rows);

        return $this->renderer->renderToBytes(
            $variant,
            $overrides,
            $imageOverrides,
            strictContainerOverflow: true,
            format: $format,
        );
    }

    /**
     * One member dimension of a group fill: the shared values plus THIS
     * dimension's placements.
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $placements variantId → imageInputId → transform
     * @param array<array-key, mixed> $shapeColors shared by every dimension (members share shape ids)
     * @param array<array-key, mixed> $offsets variantId → element id → `{x, y}` (per dimension)
     * @param array<array-key, mixed> $rows the shared `rows` (one count per row for every dimension)
     */
    public function renderGroupMember(
        TemplateVariant $variant,
        array $inputs,
        array $images,
        array $placements,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $shapeColors = [],
        array $offsets = [],
        array $rows = [],
    ): string {
        $applied = self::groupMemberValues($variant, $inputs, $images, $placements, $shapeColors, $rows);

        return $this->render(
            $variant,
            $applied->inputs,
            $applied->images,
            $format,
            $applied->shapeColors,
            self::offsetsForVariant($offsets, $variant->id->toString()),
            $applied,
        );
    }

    /**
     * One group dimension's values: the shared body with its `rows` applied
     * on THIS member (a row id the member does not carry is skipped; the
     * group-wide check refuses ids no member knows), then the dimension's
     * `placements` layered over the picks — so a placement may address a
     * row's slot id (`repeatableRows[].slots[].ids`).
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $placements
     * @param array<array-key, mixed> $shapeColors
     * @param array<array-key, mixed> $rows
     *
     * @throws InvalidExportValue
     */
    public static function groupMemberValues(TemplateVariant $variant, array $inputs, array $images, array $placements, array $shapeColors, array $rows): AppliedApiRows
    {
        $applied = ApiRepeatableRows::apply(RepeatableRows::expand($variant), $rows, $inputs, $images, $shapeColors, groupMember: true);

        return $applied->withImages(self::imagesForVariant($applied->images, $placements, $variant->id->toString()));
    }

    /**
     * One dimension's element moves out of a group body's `offsets`
     * (variantId → element id → `{x, y}`): each size moves on its own, like
     * `placements`. Keys of other variants are simply not this dimension's.
     *
     * @param array<array-key, mixed> $offsets
     * @return array<array-key, mixed>
     */
    public static function offsetsForVariant(array $offsets, string $variantId): array
    {
        $variantOffsets = $offsets[$variantId] ?? [];

        if (!is_array($variantOffsets)) {
            throw InvalidExportValue::invalidValue(sprintf('offsets.%s must be an object keyed by element id.', $variantId));
        }

        return $variantOffsets;
    }

    /**
     * One variant's element moves, STRICT (the API contract) and canonical
     * (rounded, zero moves dropped, sorted) — what the renderer bakes and
     * what a recorded version stores under the variant's id.
     *
     * @param array<array-key, mixed> $offsets element id → `{x, y}`
     * @return array<string, array{x: float, y: float}>
     *
     * @throws InvalidExportValue
     */
    public static function resolveOffsets(TemplateVariant $variant, array $offsets): array
    {
        return ResolveElementOffsets::forVariant(RepeatableRows::expand($variant), self::stringKeyed($offsets), lenient: false);
    }

    /**
     * Every member's element moves of a group body, resolved STRICTLY up
     * front — the recorded version carries all dimensions' moves, so a move
     * on a dimension that is not rendered by this call is validated too.
     * Returns the canonical maps (variantId → id → `{x, y}`, zero moves
     * dropped) ready for {@see \WBoost\Web\Value\ExportFillValues::withOffsets()}.
     *
     * @param list<TemplateVariant> $members
     * @param array<array-key, mixed> $offsets
     * @return array<string, array<string, array{x: float, y: float}>>
     *
     * @throws InvalidExportValue with `variantId` in its context
     */
    public static function resolveGroupOffsets(array $members, array $offsets): array
    {
        $resolved = [];

        foreach ($members as $member) {
            $variantId = $member->id->toString();

            try {
                $map = self::resolveOffsets($member, self::offsetsForVariant($offsets, $variantId));
            } catch (InvalidExportValue $invalid) {
                throw $invalid->withContext(['variantId' => $variantId]);
            }

            if ($map !== []) {
                $resolved[$variantId] = $map;
            }
        }

        return $resolved;
    }

    /**
     * Layers a dimension's placement over the shared picture pick, the way the
     * web group fill does: a slot's per-dimension entry REPLACES the shared
     * transform wholesale (a partial merge would render a placement the user
     * never saw). A placement only applies to a slot that actually carries a
     * picture in `images`; one without (unfilled, hidden) is ignored rather
     * than 400'd — the picture is picked once for the group, the placement is
     * per dimension, so the two legitimately drift apart while editing.
     *
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $placements
     * @return array<string, mixed>
     */
    public static function imagesForVariant(array $images, array $placements, string $variantId): array
    {
        $variantPlacements = $placements[$variantId] ?? [];

        if (!is_array($variantPlacements)) {
            throw InvalidExportValue::invalidImageValue(sprintf('placements.%s must be an object keyed by imageInputId.', $variantId));
        }

        $merged = [];

        foreach ($images as $inputId => $value) {
            $key = (string) $inputId;
            $placement = $variantPlacements[$key] ?? null;

            if ($placement !== null && !is_array($placement)) {
                throw InvalidExportValue::invalidImageValue(sprintf('placements.%s.%s must be an object.', $variantId, $key))
                    ->withContext(['imageInputId' => $key, 'variantId' => $variantId]);
            }

            $base = is_string($value) ? ['imageId' => $value] : $value;

            if (!is_array($placement) || !is_array($base) || !is_string($base['imageId'] ?? null) || $base['imageId'] === '') {
                $merged[$key] = $value;
                continue;
            }

            foreach (self::TRANSFORM_FIELDS as $field) {
                unset($base[$field]);

                if (array_key_exists($field, $placement)) {
                    $base[$field] = $placement[$field];
                }
            }

            $merged[$key] = $base;
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $shapeColors
     * @param array<array-key, mixed> $offsets element id → `{x, y}` of THIS variant
     * @param array<array-key, mixed>|AppliedApiRows $rows the body's `rows`, or already applied (then
     *   `$inputs` / `$images` / `$shapeColors` are its maps)
     * @return array{ResolvedInputOverrides, ResolvedImageOverrides}
     *
     * Public (STRICT, like every API render) for multi-page documents: all
     * pages are resolved before any is rendered, so a 400 on the last page
     * never arrives after minutes of renders.
     *
     * @throws InvalidExportValue
     * @throws InvalidRichTextValue
     */
    public function resolve(TemplateVariant $variant, array $inputs, array $images, array $shapeColors, array $offsets = [], array|AppliedApiRows $rows = []): array
    {
        // Repeatable rows: resolve against the EXPANDED variant (idempotent —
        // the render methods above already hand it over). The body's `rows`
        // land on the rows' slot ids; without them every row shows its
        // default count.
        $variant = RepeatableRows::expand($variant);

        if (!$rows instanceof AppliedApiRows) {
            $rows = ApiRepeatableRows::apply($variant, $rows, $inputs, $images, $shapeColors);
            [$inputs, $images, $shapeColors] = [$rows->inputs, $rows->images, $rows->shapeColors];
        }

        try {
            // `inputs[<containerId>] = {hide: true}` hides a whole container:
            // refuse a malformed entry / a non-hidable container up front (the
            // resolver below only collects the requests, the renderer expands).
            ValidateContainerHides::strictForCanvas($variant->canvas, $variant->inputs, $inputs);

            $overrides = $this->resolveTextOverrides->resolve(
                $variant->inputs,
                self::stringKeyed($inputs),
                richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
            )->withShapeColors($this->resolveShapeColorOverrides->forVariant($variant, self::stringKeyed($shapeColors), lenient: false))
                ->withOffsets(self::resolveOffsets($variant, $offsets))
                ->withRowCounts($rows->counts);

            $imageOverrides = $this->resolveImageOverrides->resolve(
                $variant->imageInputs,
                $variant->template->project->id,
                self::stringKeyed($images),
            );
        } catch (InvalidExportValue|InvalidRichTextValue $invalid) {
            throw $rows->tag($invalid);
        }

        return [$overrides, $imageOverrides];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
