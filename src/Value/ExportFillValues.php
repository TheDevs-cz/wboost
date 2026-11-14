<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Services\Template\FillValueGuard;

/**
 * The canonical snapshot of one fill — everything a user provided for an
 * export, in the WEB WIRE SHAPE (the fill form's field values), so a stored
 * version can seed the fill pages back verbatim:
 *
 * - `texts`: inputId → the mirror string (plain text OR a rich
 *   `{"runs":…,"lines":…}` envelope, exactly as the mirror smuggles it).
 * - `hidden`: inputIds explicitly hidden (checkbox semantics: presence = hide).
 * - `fonts`: inputId → the whole-text font choice (`fontValues[…]` select /
 *   the API value's `fontFamily`); only non-empty picks are kept, "" being
 *   the "designed font" option.
 * - `images`: imageInputId → `{imageId?, scale?, offsetX?, offsetY?,
 *   offsetXRatio?, offsetYRatio?, rotation?, hide?}`.
 * - `placements`: group exports only — variantId → inputId → the
 *   per-dimension placement override (`imagePlacements[…]` fields).
 * - `shapeColors`: shape inputId → the `#rrggbb` a recolourable shape was
 *   repainted in (`shapeColorValues[…]` / the API's `shapeColors`); "" (the
 *   designed colour) is not kept.
 * - `rows`: repeatable row container id → the row count the fill showed
 *   (`rowCounts[…]`, container-rows P2). The rows' VALUES are ordinary
 *   entries of the maps above, keyed by the rows' derived ids.
 * - `offsets`: variantId → element id → `{x, y}` — the user's element moves
 *   ("Uživatel může posunout", M2) in canvas px of THAT variant (per size:
 *   a group / document stores one map per dimension / page; a single fill
 *   one map under its own variant). Rounded to 1/100 px, zero moves dropped;
 *   the seeders re-clamp to the CURRENT allowance.
 *
 * The array form is CANONICAL — keys sorted recursively, floats rounded to 4
 * decimals, empty entries dropped — so `hash()` is stable across field order,
 * float noise and repeated round-trips, which is what version deduplication
 * rests on: the same fill always hashes the same. The `fonts` key is emitted
 * ONLY when a font was picked: it arrived after the first versions were
 * recorded, and an always-present empty key would re-hash every stored
 * font-less fill, duplicating each one on its next identical export. The
 * `shapeColors`, `rows` and `offsets` keys follow the same rule for the same
 * reason.
 *
 * API / MCP exports are converted INTO this shape (rich runs re-encoded as the
 * envelope string) so one history lists every channel's exports uniformly.
 */
final readonly class ExportFillValues
{
    private const array IMAGE_FLOAT_FIELDS = ['scale', 'offsetX', 'offsetY', 'offsetXRatio', 'offsetYRatio', 'rotation'];

    /** @var array<string, string> */
    public array $texts;

    /** @var list<string> */
    public array $hidden;

    /** @var array<string, array<string, mixed>> */
    public array $images;

    /** @var array<string, array<string, array<string, float>>> */
    public array $placements;

    /** @var array<string, string> */
    public array $fonts;

    /** @var array<string, string> */
    public array $shapeColors;

    /** @var array<string, int> */
    public array $rows;

    /** @var array<string, array<string, array{x: float, y: float}>> */
    public array $offsets;

    /**
     * Whatever a fill is built from, it holds only what its JSONB column
     * takes: U+0000 and bytes that are not UTF-8 are cleaned out of every
     * key and value ({@see FillValueGuard::clean()}). The strict surfaces
     * (API, MCP) refuse such a request before it gets here; the lenient web
     * forms rely on this — the version is recorded, without the character.
     *
     * @param array<string, string> $texts
     * @param list<string> $hidden
     * @param array<string, array<string, mixed>> $images
     * @param array<string, array<string, array<string, float>>> $placements
     * @param array<string, string> $fonts
     * @param array<string, string> $shapeColors
     * @param array<string, int> $rows
     * @param array<string, array<string, array{x: float, y: float}>> $offsets
     */
    private function __construct(
        array $texts,
        array $hidden,
        array $images,
        array $placements,
        array $fonts = [],
        array $shapeColors = [],
        array $rows = [],
        array $offsets = [],
    ) {
        // The shapes hold: cleaning changes characters, never a type.
        /** @var array<string, string> $texts */
        $texts = FillValueGuard::clean($texts);
        /** @var list<string> $hidden */
        $hidden = FillValueGuard::clean($hidden);
        /** @var array<string, array<string, mixed>> $images */
        $images = FillValueGuard::clean($images);
        /** @var array<string, array<string, array<string, float>>> $placements */
        $placements = FillValueGuard::clean($placements);
        /** @var array<string, string> $fonts */
        $fonts = FillValueGuard::clean($fonts);
        /** @var array<string, string> $shapeColors */
        $shapeColors = FillValueGuard::clean($shapeColors);
        /** @var array<string, int> $rows */
        $rows = FillValueGuard::clean($rows);
        /** @var array<string, array<string, array{x: float, y: float}>> $offsets */
        $offsets = FillValueGuard::clean($offsets);

        $this->texts = $texts;
        $this->hidden = $hidden;
        $this->images = $images;
        $this->placements = $placements;
        $this->fonts = $fonts;
        $this->shapeColors = $shapeColors;
        $this->rows = $rows;
        $this->offsets = $offsets;
    }

    /**
     * The same fill with the repeatable rows' counts — set by the surfaces
     * that have them (the fill forms' `rowCounts[…]`).
     *
     * @param array<array-key, mixed> $rawRowCounts
     */
    public function withRows(array $rawRowCounts): self
    {
        return new self($this->texts, $this->hidden, $this->images, $this->placements, $this->fonts, $this->shapeColors, self::rowEntries($rawRowCounts), $this->offsets);
    }

    /**
     * The same fill with the user's element moves — `offsets[<variantId>]
     * [<elementId>][x|y]` (group / document forms, raw strings welcome) or
     * the resolved map of a single fill wrapped in its variant id.
     *
     * @param array<array-key, mixed> $rawOffsets variantId → elementId → `{x?, y?}`
     */
    public function withOffsets(array $rawOffsets): self
    {
        return new self($this->texts, $this->hidden, $this->images, $this->placements, $this->fonts, $this->shapeColors, $this->rows, self::offsetEntries($rawOffsets));
    }

    /**
     * The single-variant fill form (download + publish POST). Mirrors
     * FillFormRequestParser semantics — including that an EMPTY text string is
     * kept: on this surface it means "blank the text", which is part of the
     * fill.
     *
     * @param array<array-key, mixed> $rawTexts `textValues[…]`
     * @param array<array-key, mixed> $rawHidden `hiddenValues[…]`
     * @param array<array-key, mixed> $rawImages `images[…]`
     * @param array<array-key, mixed> $rawFonts `fontValues[…]`
     * @param array<array-key, mixed> $rawShapeColors `shapeColorValues[…]`
     */
    public static function fromVariantWebForm(array $rawTexts, array $rawHidden, array $rawImages, array $rawFonts = [], array $rawShapeColors = []): self
    {
        $texts = [];
        foreach ($rawTexts as $inputId => $value) {
            if (is_string($value)) {
                $texts[(string) $inputId] = $value;
            }
        }

        return new self(
            $texts,
            self::hiddenIds($rawHidden),
            self::imageEntries($rawImages),
            [],
            self::fontEntries($rawFonts),
            self::shapeColorEntries($rawShapeColors),
        );
    }

    /**
     * The group fill form. Same text semantics as the variant form since the
     * fill pages were unified: an EMPTY text means "blank the text" and is
     * part of the fill, so it is kept (mirrors GroupFillRenderer); placement
     * stays per dimension, separate from the shared picture pick.
     *
     * @param array<array-key, mixed> $rawTexts
     * @param array<array-key, mixed> $rawHidden
     * @param array<array-key, mixed> $rawImages
     * @param array<array-key, mixed> $rawPlacements `imagePlacements[<variantId>][<inputId>][…]`
     * @param array<array-key, mixed> $rawFonts `fontValues[…]`
     * @param array<array-key, mixed> $rawShapeColors `shapeColorValues[…]`
     */
    public static function fromGroupWebForm(array $rawTexts, array $rawHidden, array $rawImages, array $rawPlacements, array $rawFonts = [], array $rawShapeColors = []): self
    {
        $texts = [];
        foreach ($rawTexts as $inputId => $value) {
            if (is_string($value)) {
                $texts[(string) $inputId] = $value;
            }
        }

        $placements = [];
        foreach ($rawPlacements as $variantId => $slots) {
            if (!is_array($slots)) {
                continue;
            }

            foreach ($slots as $inputId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $placement = [];
                foreach (self::IMAGE_FLOAT_FIELDS as $field) {
                    $candidate = $entry[$field] ?? null;
                    if (is_numeric($candidate)) {
                        $placement[$field] = round((float) $candidate, 4);
                    }
                }

                if ($placement !== []) {
                    $placements[(string) $variantId][(string) $inputId] = $placement;
                }
            }
        }

        return new self(
            $texts,
            self::hiddenIds($rawHidden),
            self::imageEntries($rawImages),
            $placements,
            self::fontEntries($rawFonts),
            self::shapeColorEntries($rawShapeColors),
        );
    }

    /**
     * The API / MCP export request (`inputs` + `images` maps). Values are
     * re-encoded into the web wire shape: `{value, hide}` unwraps, rich
     * `{runs, lines}` becomes the envelope STRING the web mirrors carry, a
     * `fontFamily` lands in `fonts`, so a version recorded through the API
     * seeds the web fill page like any other.
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $shapeColors the request's `shapeColors` map
     */
    public static function fromApiRequest(array $inputs, array $images, array $shapeColors = []): self
    {
        $texts = [];
        $hidden = [];
        $fonts = [];

        foreach ($inputs as $inputId => $value) {
            $key = (string) $inputId;

            if (is_string($value)) {
                $texts[$key] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if (is_string($value['fontFamily'] ?? null) && $value['fontFamily'] !== '') {
                $fonts[$key] = $value['fontFamily'];
            }

            if (isset($value['runs']) && is_array($value['runs'])) {
                $envelope = ['runs' => array_values($value['runs'])];
                if (isset($value['lines']) && is_array($value['lines'])) {
                    $envelope['lines'] = array_values($value['lines']);
                }
                $encoded = json_encode($envelope);
                if (is_string($encoded)) {
                    $texts[$key] = $encoded;
                }
            } elseif (is_string($value['value'] ?? null)) {
                $texts[$key] = $value['value'];
            }

            if (($value['hide'] ?? false) === true) {
                $hidden[] = $key;
            }
        }

        return new self($texts, $hidden, self::imageEntries($images), [], $fonts, self::shapeColorEntries($shapeColors));
    }

    /**
     * The API GROUP export request: the shared `inputs` + `images` maps (the
     * single-variant API shape) plus per-dimension `placements`
     * (variantId → imageInputId → transform) — the same snapshot the web group
     * fill form produces, so API and web group exports share one history and
     * deduplicate against each other.
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $placements
     * @param array<array-key, mixed> $shapeColors
     */
    public static function fromGroupApiRequest(array $inputs, array $images, array $placements, array $shapeColors = []): self
    {
        $values = self::fromApiRequest($inputs, $images, $shapeColors);
        $group = self::fromGroupWebForm([], [], [], $placements);

        return new self($values->texts, $values->hidden, $values->images, $group->placements, $values->fonts, $values->shapeColors, $values->rows);
    }

    /**
     * Defensive read of a stored snapshot (the Doctrine type's PHP side).
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $texts = [];
        foreach (is_array($data['texts'] ?? null) ? $data['texts'] : [] as $inputId => $value) {
            if (is_string($value)) {
                $texts[(string) $inputId] = $value;
            }
        }

        $hidden = [];
        foreach (is_array($data['hidden'] ?? null) ? $data['hidden'] : [] as $inputId) {
            if (is_string($inputId)) {
                $hidden[] = $inputId;
            }
        }

        $images = [];
        foreach (is_array($data['images'] ?? null) ? $data['images'] : [] as $inputId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fields = [];
            foreach ($entry as $field => $value) {
                $fields[(string) $field] = $value;
            }
            $images[(string) $inputId] = $fields;
        }

        $placements = [];
        foreach (is_array($data['placements'] ?? null) ? $data['placements'] : [] as $variantId => $slots) {
            foreach (is_array($slots) ? $slots : [] as $inputId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $placement = [];
                foreach ($entry as $field => $value) {
                    if (is_numeric($value)) {
                        $placement[(string) $field] = (float) $value;
                    }
                }
                if ($placement !== []) {
                    $placements[(string) $variantId][(string) $inputId] = $placement;
                }
            }
        }

        return new self(
            $texts,
            $hidden,
            $images,
            $placements,
            self::fontEntries(is_array($data['fonts'] ?? null) ? $data['fonts'] : []),
            self::shapeColorEntries(is_array($data['shapeColors'] ?? null) ? $data['shapeColors'] : []),
            self::rowEntries(is_array($data['rows'] ?? null) ? $data['rows'] : []),
            self::offsetEntries(is_array($data['offsets'] ?? null) ? $data['offsets'] : []),
        );
    }

    /**
     * Canonical form: recursively key-sorted, empty top-level keys kept (the
     * shape is fixed — except `fonts`, see the class docblock), suitable for
     * storage AND hashing.
     *
     * @return array{texts: array<string, string>, hidden: list<string>, images: array<string, array<string, mixed>>, placements: array<string, array<string, array<string, float>>>, fonts?: array<string, string>, shapeColors?: array<string, string>, rows?: array<string, int>, offsets?: array<string, array<string, array{x: float, y: float}>>}
     */
    public function toArray(): array
    {
        $texts = $this->texts;
        ksort($texts);

        $hidden = array_values(array_unique($this->hidden));
        sort($hidden);

        $images = [];
        foreach ($this->images as $inputId => $entry) {
            ksort($entry);
            $images[$inputId] = $entry;
        }
        ksort($images);

        $placements = [];
        foreach ($this->placements as $variantId => $slots) {
            $sorted = [];
            foreach ($slots as $inputId => $entry) {
                ksort($entry);
                $sorted[$inputId] = $entry;
            }
            ksort($sorted);
            $placements[$variantId] = $sorted;
        }
        ksort($placements);

        $canonical = [
            'texts' => $texts,
            'hidden' => $hidden,
            'images' => $images,
            'placements' => $placements,
        ];

        if ($this->fonts !== []) {
            $fonts = $this->fonts;
            ksort($fonts);
            $canonical['fonts'] = $fonts;
        }

        if ($this->shapeColors !== []) {
            $shapeColors = $this->shapeColors;
            ksort($shapeColors);
            $canonical['shapeColors'] = $shapeColors;
        }

        if ($this->rows !== []) {
            $rows = $this->rows;
            ksort($rows);
            $canonical['rows'] = $rows;
        }

        if ($this->offsets !== []) {
            // Already canonical (sorted, rounded, zero-free) — see offsetEntries().
            $canonical['offsets'] = $this->offsets;
        }

        return $canonical;
    }

    /**
     * Content identity of the fill — two exports with the same hash are the
     * same version.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
    }

    public function isEmpty(): bool
    {
        return $this->texts === [] && $this->hidden === [] && $this->images === [] && $this->placements === [] && $this->fonts === [] && $this->shapeColors === [] && $this->rows === [] && $this->offsets === [];
    }

    /**
     * Non-empty string picks only — "" is the select's "výchozí" option.
     *
     * @param array<array-key, mixed> $rawFonts
     * @return array<string, string>
     */
    private static function fontEntries(array $rawFonts): array
    {
        $fonts = [];
        foreach ($rawFonts as $inputId => $value) {
            if (is_string($value) && $value !== '') {
                $fonts[(string) $inputId] = $value;
            }
        }

        return $fonts;
    }

    /**
     * Non-empty picks only — "" is the designed colour. Valid hexes are
     * normalized (lowercase `#rrggbb`) so `#FFF` and `#ffffff` hash the same;
     * anything else is kept verbatim (an API request is recorded only after
     * the strict resolver accepted it, a web value is re-validated on seed).
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, string>
     */
    private static function shapeColorEntries(array $raw): array
    {
        $colors = [];
        foreach ($raw as $inputId => $value) {
            if (is_string($value) && $value !== '') {
                $colors[(string) $inputId] = RichText::normalizeHexColor($value) ?? $value;
            }
        }

        return $colors;
    }

    /**
     * Whole non-negative counts only (form strings included) — the seeder
     * clamps them to the row's CURRENT limits.
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, int>
     */
    private static function rowEntries(array $raw): array
    {
        $rows = [];
        foreach ($raw as $rowId => $value) {
            if (is_int($value) && $value >= 0) {
                $rows[(string) $rowId] = $value;
            } elseif (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1) {
                $rows[(string) $rowId] = (int) $value;
            }
        }

        return $rows;
    }

    /**
     * variantId → elementId → `{x, y}`: numeric values only (form strings
     * included), rounded to 1/100 px like the resolver, zero moves and empty
     * variants dropped, both levels key-sorted — canonical on construction,
     * so a stored snapshot (whose JSONB turns 12.0 into 12) re-hashes
     * identically.
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, array<string, array{x: float, y: float}>>
     */
    private static function offsetEntries(array $raw): array
    {
        $offsets = [];
        foreach ($raw as $variantId => $elements) {
            if (!is_array($elements)) {
                continue;
            }
            $entries = [];
            foreach ($elements as $elementId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $x = is_numeric($entry['x'] ?? null) ? ElementMoveAllowance::round2((float) $entry['x']) : 0.0;
                $y = is_numeric($entry['y'] ?? null) ? ElementMoveAllowance::round2((float) $entry['y']) : 0.0;
                if ($x != 0.0 || $y != 0.0) {
                    $entries[(string) $elementId] = ['x' => $x, 'y' => $y];
                }
            }
            if ($entries !== []) {
                ksort($entries, SORT_STRING);
                $offsets[(string) $variantId] = $entries;
            }
        }
        ksort($offsets, SORT_STRING);

        return $offsets;
    }

    /**
     * @param array<array-key, mixed> $rawHidden
     * @return list<string>
     */
    private static function hiddenIds(array $rawHidden): array
    {
        $hidden = [];
        foreach ($rawHidden as $inputId => $_) {
            $hidden[] = (string) $inputId;
        }

        return $hidden;
    }

    /**
     * Mirrors FillFormRequestParser::parseImageValues — shorthand string pick,
     * numeric transform fields, checkbox-ish hide — with floats rounded so an
     * identical re-export hashes identically.
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, array<string, mixed>>
     */
    private static function imageEntries(array $raw): array
    {
        $images = [];

        foreach ($raw as $inputId => $value) {
            $key = (string) $inputId;

            if (is_string($value)) {
                if ($value !== '') {
                    $images[$key] = ['imageId' => $value];
                }
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $entry = [];

            $imageId = $value['imageId'] ?? null;
            if (is_string($imageId) && $imageId !== '') {
                $entry['imageId'] = $imageId;
            }

            foreach (self::IMAGE_FLOAT_FIELDS as $field) {
                $candidate = $value[$field] ?? null;
                if (is_numeric($candidate)) {
                    $entry[$field] = round((float) $candidate, 4);
                }
            }

            if (in_array($value['hide'] ?? null, ['1', 'true', true, 1], true)) {
                $entry['hide'] = true;
            }

            if ($entry !== []) {
                $images[$key] = $entry;
            }
        }

        return $images;
    }
}
