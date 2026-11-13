<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Services\Font\FitTextToWidth;

/**
 * Turns a scan's proposal plus what the user decided in the review into the
 * design document that is rendered (the review's picture) and, at accept,
 * written as the template.
 *
 * **Replay, not mutation.** The proposal's document is never changed. The
 * review stores decisions — `fixes.items`, one entry per thing decided, keyed
 * by that thing (`font:{group}`, `text:{id}`, `place:{id}`, `shrink`, …) — and
 * the picture choices (`pictures.places`). This class applies them to the
 * original document in a fixed order, every time. So "Vrátit" is deleting an
 * entry, two tabs meet per item (the last write of an item wins), and no
 * sequence of clicks can leave a document nobody can explain.
 *
 * Order: places added from left-out things → place removals, merges and
 * conversions to shapes → text edits, names and input / fixed → undo of the
 * automatic shrink → font swaps with the metric re-fit (a swapped text is
 * fitted to the picture again, whatever the shrink did) → palette revert →
 * pictures (kind, fit, the chosen file).
 *
 * Two outputs from the same replay ({@see document()}):
 *
 * - the **review** document (`$standInAssetId = null`): empty places keep the
 *   reserved stand-in id the candidate was rendered with;
 * - the **template** document (`$standInAssetId` = the project's stand-in
 *   row): what accept compiles — an empty "Součást šablony" place is a
 *   decorative image on the stand-in (the export guard finds it), an empty
 *   "Mění se" place a fillable slot without a sample, a background always
 *   carries a picture.
 *
 * Shapes of the two stored maps are documented in
 * docs/architecture/ai-scan.md § "Review state".
 */
final readonly class ApplyFixes
{
    public const string KIND_FIXED = 'fixed';
    public const string KIND_INPUT = 'input';
    public const string FIT_CONTAIN = 'contain';
    public const string FIT_COVER = 'cover';

    /** Roles whose place is part of the template by default (ux.md S4b). */
    private const array FIXED_ROLES = ['logo', 'icon', 'pattern'];
    private const array CONTAIN_ROLES = ['logo', 'icon'];
    public const array SHAPES = ['rectangle', 'circle', 'star'];

    public function __construct(
        private FitTextToWidth $fitTextToWidth,
    ) {
    }

    /**
     * @param array<string, mixed> $proposal `ai_scan.proposal`
     * @param array<string, mixed> $report `ai_scan.report`
     * @param array<string, array<string, mixed>> $items `fixes.items`
     * @param array<string, array<string, mixed>> $places `pictures.places`
     * @param array<string, array{int, int}> $pictureSizes pixel size of the chosen files, by file id
     * @param null|string $standInAssetId null = the review document; the project's stand-in row id = the template document
     * @return array{canvas: array<string, mixed>, elements: list<array<string, mixed>>, places: list<string>} `places` — the ids of the places for pictures, in stack order (a filled place is an ordinary picture in the document, so the document alone no longer says); the design document is `canvas` + `elements`
     */
    public function replay(
        UuidInterface $projectId,
        array $proposal,
        array $report,
        array $items,
        array $places,
        array $pictureSizes = [],
        null|string $standInAssetId = null,
    ): array {
        $document = is_array($proposal['document'] ?? null) ? $proposal['document'] : [];
        /** @var array<string, mixed> $canvas */
        $canvas = is_array($document['canvas'] ?? null) ? $document['canvas'] : [];
        $elements = self::rows($document['elements'] ?? null);
        $measured = self::measured($proposal);
        $scale = self::sourceScale($proposal);

        $elements = $this->addedPlaces($elements, $items, $report, $scale);
        $elements = $this->placeOperations($elements, $items, $measured, $scale, $report);
        $elements = $this->textEdits($elements, $items);
        $elements = self::shrinkUndone($elements, $items, $report, $measured, $scale);
        $elements = $this->fontSwaps($projectId, $elements, $items, $report, $measured, $scale);
        $elements = self::paletteReverted($elements, $items, $report);
        $placeIds = array_values(array_filter(array_column(self::placesOf($elements), 'id'), is_string(...)));
        $elements = self::pictures($elements, $places, $report, $pictureSizes, $standInAssetId);

        return ['canvas' => $canvas, 'elements' => $elements, 'places' => $placeIds];
    }

    /**
     * The same document at another size: every length is multiplied by
     * `$factor` (accept uses it when the template's format is larger than
     * the picture — `2×`, a print size).
     *
     * @param array{canvas: array<string, mixed>, elements: list<array<string, mixed>>} $document
     * @return array{canvas: array<string, mixed>, elements: list<array<string, mixed>>}
     */
    public static function scaled(array $document, int $width, int $height): array
    {
        $from = self::number($document['canvas']['width'] ?? null);

        if ($from === null || $from <= 0.0 || (int) $from === $width) {
            return $document;
        }

        $factor = $width / $from;
        $elements = [];

        foreach ($document['elements'] as $element) {
            foreach (['x', 'y', 'width', 'height', 'size', 'strokeWidth', 'cornerRadius', 'maxHeight', 'gap', 'spaceAfter'] as $key) {
                $value = self::number($element[$key] ?? null);

                if ($value !== null) {
                    $element[$key] = round($value * $factor, 2);
                }
            }

            $elements[] = $element;
        }

        return ['canvas' => ['width' => $width, 'height' => $height] + $document['canvas'], 'elements' => $elements];
    }

    /**
     * The places for pictures of a document, in stack order: fillable or
     * stand-in `image` elements and a `background` that carries a picture.
     *
     * @param list<array<string, mixed>> $elements
     * @return list<array<string, mixed>>
     */
    public static function placesOf(array $elements): array
    {
        return array_values(array_filter($elements, self::isPlace(...)));
    }

    /**
     * @param array<string, mixed> $element
     */
    public static function isPlace(array $element): bool
    {
        $kind = $element['kind'] ?? null;

        if ($kind === 'background') {
            return ($element['asset'] ?? null) !== null;
        }

        return $kind === 'image' && (is_array($element['input'] ?? null) || ($element['asset'] ?? null) === AiScanStandIn::ASSET_ID);
    }

    public static function defaultKind(null|string $role, bool $background): string
    {
        return !$background && in_array($role, self::FIXED_ROLES, true) ? self::KIND_FIXED : self::KIND_INPUT;
    }

    public static function defaultFit(null|string $role): string
    {
        return in_array($role, self::CONTAIN_ROLES, true) ? self::FIT_CONTAIN : self::FIT_COVER;
    }

    /**
     * What the scanner measured per element, by element id.
     *
     * @param array<string, mixed> $proposal
     * @return array<string, array<string, mixed>>
     */
    public static function measured(array $proposal): array
    {
        $raw = $proposal['measured'] ?? null;
        $byId = [];

        if (!is_array($raw)) {
            return $byId;
        }

        // The scanner sends a list of `{id, kind, box, …}`; an earlier shape
        // was `{elements: {id: {…}}}` — both are read.
        $rows = is_array($raw['elements'] ?? null) ? $raw['elements'] : (is_array($raw['boxes'] ?? null) ? $raw['boxes'] : $raw);

        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_string($row['id'] ?? null) ? $row['id'] : (is_string($key) ? $key : null);

            if ($id !== null) {
                /** @var array<string, mixed> $row */
                $byId[$id] = $row;
            }
        }

        return $byId;
    }

    /**
     * Source pixels → canvas pixels. Report boxes are in the working copy's
     * pixels; the document may be authored at another size.
     *
     * @param array<string, mixed> $proposal
     * @return array{float, float}
     */
    public static function sourceScale(array $proposal): array
    {
        $size = self::sourceSize($proposal);
        $document = is_array($proposal['document'] ?? null) ? $proposal['document'] : [];
        $canvas = is_array($document['canvas'] ?? null) ? $document['canvas'] : [];
        $width = self::number($canvas['width'] ?? null);
        $height = self::number($canvas['height'] ?? null);

        if ($size === null || $width === null || $height === null || $size[0] <= 0.0 || $size[1] <= 0.0) {
            return [1.0, 1.0];
        }

        return [$width / $size[0], $height / $size[1]];
    }

    /**
     * The size of the picture the report's boxes are measured in: the
     * measured box of the background (always the whole picture), else the
     * canvas.
     *
     * @param array<string, mixed> $proposal
     * @return null|array{float, float}
     */
    public static function sourceSize(array $proposal): null|array
    {
        foreach (self::measured($proposal) as $row) {
            if (($row['kind'] ?? null) === 'background') {
                $box = self::box($row['box'] ?? null);

                if ($box !== null && $box[2] > 0.0 && $box[3] > 0.0) {
                    return [$box[2], $box[3]];
                }
            }
        }

        $document = is_array($proposal['document'] ?? null) ? $proposal['document'] : [];
        $canvas = is_array($document['canvas'] ?? null) ? $document['canvas'] : [];
        $width = self::number($canvas['width'] ?? null);
        $height = self::number($canvas['height'] ?? null);

        return $width === null || $height === null ? null : [$width, $height];
    }

    /**
     * An element's box in canvas pixels: its own `x, y, width, height`, else
     * what the scanner measured.
     *
     * @param array<string, mixed> $element
     * @param array<string, array<string, mixed>> $measured
     * @param array{float, float} $scale
     * @return null|array{float, float, float, float}
     */
    public static function elementBox(array $element, array $measured, array $scale): null|array
    {
        $x = self::number($element['x'] ?? null);
        $y = self::number($element['y'] ?? null);
        $width = self::number($element['width'] ?? null);
        $height = self::number($element['height'] ?? null);

        if ($x !== null && $y !== null && $width !== null && $height !== null) {
            return [$x, $y, $width, $height];
        }

        $id = is_string($element['id'] ?? null) ? $element['id'] : '';
        $box = self::box($measured[$id]['box'] ?? null);

        if ($box === null) {
            return null;
        }

        return [
            $x ?? $box[0] * $scale[0],
            $y ?? $box[1] * $scale[1],
            $width ?? $box[2] * $scale[0],
            $height ?? $box[3] * $scale[1],
        ];
    }

    /**
     * @return null|array{float, float, float, float}
     */
    public static function box(mixed $raw): null|array
    {
        if (!is_array($raw)) {
            return null;
        }

        $values = array_is_list($raw)
            ? $raw
            : [$raw['x'] ?? null, $raw['y'] ?? null, $raw['w'] ?? ($raw['width'] ?? null), $raw['h'] ?? ($raw['height'] ?? null)];

        if (count($values) < 4) {
            return null;
        }

        $box = [];

        foreach ([0, 1, 2, 3] as $index) {
            $number = self::number($values[$index]);

            if ($number === null) {
                return null;
            }

            $box[] = $number;
        }

        return [$box[0], $box[1], $box[2], $box[3]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $raw): array
    {
        $rows = [];

        foreach (is_array($raw) ? $raw : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public static function number(mixed $value): null|float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    public static function addedPlaceId(int $index): string
    {
        return sprintf('misto-%d', $index + 1);
    }

    // ---- the steps ----------------------------------------------------------

    /**
     * "Přidat místo pro obrázek" on a left-out thing: a new place over the
     * box the report gave it, on top of the stack.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $items
     * @param array<string, mixed> $report
     * @param array{float, float} $scale
     * @return list<array<string, mixed>>
     */
    private function addedPlaces(array $elements, array $items, array $report, array $scale): array
    {
        foreach (self::rows($report['omitted'] ?? null) as $index => $omitted) {
            if (($items['omitted:' . $index]['decision'] ?? null) !== 'place') {
                continue;
            }

            $box = self::box($omitted['box'] ?? null);

            if ($box === null) {
                continue;
            }

            $elements[] = [
                'kind' => 'image',
                'id' => self::addedPlaceId($index),
                'asset' => AiScanStandIn::ASSET_ID,
                'x' => round($box[0] * $scale[0], 2),
                'y' => round($box[1] * $scale[1], 2),
                'width' => max(1.0, round($box[2] * $scale[0], 2)),
                'height' => max(1.0, round($box[3] * $scale[1], 2)),
                'input' => ['name' => sprintf('Obrázek %d', $index + 1)],
            ];
        }

        return $elements;
    }

    /**
     * Remove, merge, change to a shape — in stack order, so the result does
     * not depend on the order the user clicked in.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $measured
     * @param array{float, float} $scale
     * @param array<string, mixed> $report
     * @return list<array<string, mixed>>
     */
    private function placeOperations(array $elements, array $items, array $measured, array $scale, array $report): array
    {
        $absorbed = [];

        foreach ($elements as $element) {
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $item = $items['place:' . $id] ?? null;

            if ($item === null || ($item['op'] ?? null) !== 'merge' || isset($absorbed[$id])) {
                continue;
            }

            foreach (is_array($item['with'] ?? null) ? $item['with'] : [] as $other) {
                if (is_string($other) && $other !== $id) {
                    $absorbed[$other] = $id;
                }
            }
        }

        $result = [];

        foreach ($elements as $element) {
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $item = self::isPlace($element) ? ($items['place:' . $id] ?? null) : null;
            $background = ($element['kind'] ?? null) === 'background';

            if (isset($absorbed[$id]) && !$background) {
                continue;
            }

            $op = $item['op'] ?? null;

            if ($op === 'remove') {
                if ($background) {
                    // A design without a background exports transparent: the
                    // place becomes the colour measured for the page.
                    $result[] = ['kind' => 'background', 'id' => $id, 'fill' => self::colour($item['colour'] ?? null) ?? self::pageColour($report)];
                }

                continue;
            }

            if ($background || $op === null) {
                $result[] = $element;

                continue;
            }

            $box = self::elementBox($element, $measured, $scale);

            if ($box === null) {
                $result[] = $element;

                continue;
            }

            if ($op === 'merge') {
                foreach ($elements as $other) {
                    $otherId = is_string($other['id'] ?? null) ? $other['id'] : '';
                    $otherBox = ($absorbed[$otherId] ?? null) === $id && self::isPlace($other) ? self::elementBox($other, $measured, $scale) : null;

                    if ($otherBox !== null) {
                        $right = max($box[0] + $box[2], $otherBox[0] + $otherBox[2]);
                        $bottom = max($box[1] + $box[3], $otherBox[1] + $otherBox[3]);
                        $box[0] = min($box[0], $otherBox[0]);
                        $box[1] = min($box[1], $otherBox[1]);
                        $box[2] = $right - $box[0];
                        $box[3] = $bottom - $box[1];
                    }
                }

                unset($element['at']);
                $result[] = ['x' => round($box[0], 2), 'y' => round($box[1], 2), 'width' => round($box[2], 2), 'height' => round($box[3], 2)] + $element;

                continue;
            }

            if ($op === 'shape') {
                $shape = in_array($item['shape'] ?? null, self::SHAPES, true) ? $item['shape'] : 'rectangle';
                $name = is_array($element['input'] ?? null) && is_string($element['input']['name'] ?? null) ? $element['input']['name'] : null;

                $result[] = array_filter([
                    'kind' => 'shape',
                    'id' => $id,
                    'shape' => $shape,
                    'fill' => self::colour($item['colour'] ?? null) ?? self::pageColour($report, '#9aa0a6'),
                    'name' => $name,
                    'x' => round($box[0], 2),
                    'y' => round($box[1], 2),
                    'width' => round($box[2], 2),
                    'height' => round($box[3], 2),
                    'opacity' => self::number($element['opacity'] ?? null),
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            $result[] = $element;
        }

        return $result;
    }

    /**
     * Any text may be edited; a text is a field the user fills (default) or
     * a fixed text; every layer may be renamed.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function textEdits(array $elements, array $items): array
    {
        foreach ($elements as $index => $element) {
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $kind = $element['kind'] ?? null;
            $name = $items['name:' . $id]['name'] ?? null;

            if ($kind === 'text') {
                $text = $items['text:' . $id]['text'] ?? null;

                if (is_string($text)) {
                    $element['text'] = $text;
                }

                $input = is_array($element['input'] ?? null) ? $element['input'] : [];

                if (is_string($text)) {
                    $input = self::inputForText($input, $text);
                }

                $fillable = $items['input:' . $id]['input'] ?? null;

                if (is_bool($fillable)) {
                    $input['locked'] = !$fillable;
                }

                if (is_string($name)) {
                    $input['name'] = $name;
                }

                if ($input !== []) {
                    $element['input'] = $input;
                }
            } elseif (is_string($name)) {
                if ($kind === 'shape') {
                    $element['name'] = $name;
                } elseif ($kind === 'image') {
                    $element['input'] = ['name' => $name] + (is_array($element['input'] ?? null) ? $element['input'] : []);
                }
            }

            $elements[$index] = $element;
        }

        return $elements;
    }

    /**
     * A corrected text reaches every place the text lives. The scanner
     * writes each text twice — the element's `text` and its input's
     * `sampleValue` ("Vzorový text") — and the SAMPLE is what a saved
     * template renders, pre-fills on the fill page and exports
     * ({@see \WBoost\Web\Services\SocialNetwork\ResolveTextOverrides}). A
     * correction that changed only `text` left the old words in all three
     * (found on production, 2026-11-13). The cap follows too: a sample
     * longer than `maxLength` is silently cut at render time, so it grows
     * with the scanner's own rule (about 1.5 × the text).
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    private static function inputForText(array $input, string $text): array
    {
        if (array_key_exists('sampleValue', $input) && $input['sampleValue'] !== null) {
            $input['sampleValue'] = $text;
        }

        $length = mb_strlen($text);
        $maxLength = $input['maxLength'] ?? null;

        if (is_int($maxLength) && $maxLength < $length) {
            $input['maxLength'] = (int) ceil($length * 1.5);
        }

        return $input;
    }

    /**
     * "Vybrat jiné písmo": every text of the group switches to the face and
     * is re-fitted from font metrics — `size = source line width ÷ advance
     * at 1 px` — so its lines come out as wide as in the picture.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $items
     * @param array<string, mixed> $report
     * @param array<string, array<string, mixed>> $measured
     * @param array{float, float} $scale
     * @return list<array<string, mixed>>
     */
    private function fontSwaps(UuidInterface $projectId, array $elements, array $items, array $report, array $measured, array $scale): array
    {
        $faceByText = [];

        foreach (self::rows($report['fonts'] ?? null) as $group) {
            $key = is_string($group['group'] ?? null) ? $group['group'] : '';
            $face = $items['font:' . $key]['face'] ?? null;

            if (!is_string($face) || $face === '') {
                continue;
            }

            foreach (is_array($group['texts'] ?? null) ? $group['texts'] : [] as $textId) {
                if (is_string($textId)) {
                    $faceByText[$textId] = $face;
                }
            }
        }

        foreach ($elements as $index => $element) {
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $face = $faceByText[$id] ?? null;

            if ($face === null || ($element['kind'] ?? null) !== 'text' || ($element['font'] ?? null) === $face) {
                continue;
            }

            $size = $this->refitted($projectId, $element, $face, $measured[$id] ?? [], $scale[0]);
            $element['font'] = $face;

            if ($size !== null) {
                $element['size'] = $size;
            }

            $elements[$index] = $element;
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, mixed> $measured
     */
    private function refitted(UuidInterface $projectId, array $element, string $face, array $measured, float $scale): null|float
    {
        $text = is_string($element['text'] ?? null) ? $element['text'] : '';
        $lines = explode("\n", $text);
        // tracking (thousandths of an em) is part of the line's width in either face
        $spacing = self::number($element['letterSpacing'] ?? null) ?? 0.0;
        $widths = array_values(array_filter(
            array_map(self::number(...), is_array($measured['sourceLineWidths'] ?? null) ? $measured['sourceLineWidths'] : []),
            static fn (null|float $width): bool => $width !== null && $width > 0.0,
        ));

        // The widest measured line against the same line of the text: the
        // line that decides whether the text fits.
        if ($widths !== [] && count($widths) === count($lines)) {
            $widest = array_keys($widths, max($widths), true)[0];
            $size = $this->fitTextToWidth->fit($projectId, $face, $lines[$widest], $widths[$widest] * $scale, $spacing);

            if ($size !== null && $size > 0.0) {
                return round($size, 1);
            }
        }

        // No usable measurement (the text was edited, or a line was not
        // found): keep the width the text has now.
        $current = self::number($element['size'] ?? null);
        $font = is_string($element['font'] ?? null) ? $element['font'] : '';
        $longest = '';

        foreach ($lines as $line) {
            if (mb_strlen($line) > mb_strlen($longest)) {
                $longest = $line;
            }
        }

        $old = $this->fitTextToWidth->advanceAtOnePixel($projectId, $font, $longest, $spacing);
        $new = $this->fitTextToWidth->advanceAtOnePixel($projectId, $face, $longest, $spacing);

        if ($current === null || $old === null || $new === null || $new <= 0.0) {
            return null;
        }

        return round($current * $old / $new, 1);
    }

    /**
     * "Vrátit" on the automatic shrink: the shrunk texts get the size the
     * scanner had fitted before it made them smaller.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $items
     * @param array<string, mixed> $report
     * @param array<string, array<string, mixed>> $measured
     * @param array{float, float} $scale
     * @return list<array<string, mixed>>
     */
    private static function shrinkUndone(array $elements, array $items, array $report, array $measured, array $scale): array
    {
        if (!isset($items['shrink'])) {
            return $elements;
        }

        $shrunk = [];

        foreach (self::rows($report['shrunk'] ?? null) as $row) {
            if (is_string($row['id'] ?? null)) {
                $shrunk[$row['id']] = true;
            }
        }

        foreach ($elements as $index => $element) {
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $fitted = self::number($measured[$id]['fittedSize'] ?? null);

            if (!isset($shrunk[$id]) || $fitted === null || $fitted <= 0.0) {
                continue;
            }

            $elements[$index]['size'] = round($fitted, 1);
        }

        return $elements;
    }

    /**
     * "Vrátit přesné barvy": every colour that was snapped to the project
     * palette goes back to the colour measured in the picture.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $items
     * @param array<string, mixed> $report
     * @return list<array<string, mixed>>
     */
    private static function paletteReverted(array $elements, array $items, array $report): array
    {
        if (!isset($items['palette'])) {
            return $elements;
        }

        $back = [];

        foreach (self::rows($report['paletteSnaps'] ?? null) as $snap) {
            $from = self::colour($snap['from'] ?? null);
            $to = self::colour($snap['to'] ?? null);

            if ($from !== null && $to !== null) {
                $back[$to] ??= $from;
            }
        }

        if ($back === []) {
            return $elements;
        }

        foreach ($elements as $index => $element) {
            foreach (['color', 'fill', 'stroke'] as $key) {
                $colour = self::colour($element[$key] ?? null);

                if ($colour !== null && isset($back[$colour])) {
                    $elements[$index][$key] = $back[$colour];
                }
            }
        }

        return $elements;
    }

    /**
     * Kind, fit and the chosen file of every place.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $places
     * @param array<string, mixed> $report
     * @param array<string, array{int, int}> $pictureSizes
     * @return list<array<string, mixed>>
     */
    private static function pictures(array $elements, array $places, array $report, array $pictureSizes, null|string $standInAssetId): array
    {
        $roles = [];

        foreach (self::rows($report['placeholders'] ?? null) as $placeholder) {
            if (is_string($placeholder['id'] ?? null) && is_string($placeholder['role'] ?? null)) {
                $roles[$placeholder['id']] = $placeholder['role'];
            }
        }

        $final = $standInAssetId !== null;

        foreach ($elements as $index => $element) {
            if (!self::isPlace($element)) {
                continue;
            }

            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $place = $places[$id] ?? [];
            $fileId = is_string($place['fileId'] ?? null) && $place['fileId'] !== '' ? $place['fileId'] : null;
            $background = ($element['kind'] ?? null) === 'background';
            $role = $roles[$id] ?? null;
            $kind = !$background && in_array($place['kind'] ?? null, [self::KIND_FIXED, self::KIND_INPUT], true)
                ? $place['kind']
                : self::defaultKind($role, $background);
            $fit = in_array($place['fit'] ?? null, [self::FIT_CONTAIN, self::FIT_COVER], true) ? $place['fit'] : self::defaultFit($role);

            if ($background) {
                $element['asset'] = $fileId ?? $standInAssetId ?? AiScanStandIn::ASSET_ID;
                $element['fillable'] = true;
                $elements[$index] = $element;

                continue;
            }

            // An empty place in the REVIEW stays what the candidate drew.
            if ($fileId === null && !$final) {
                $elements[$index] = $element;

                continue;
            }

            $input = is_array($element['input'] ?? null) ? $element['input'] : [];
            unset($element['mirror']);

            if ($kind === self::KIND_FIXED) {
                // Part of the template: a decorative picture (the compiler
                // contain-fits it into the box), or the stand-in until the
                // designer adds one.
                unset($element['input']);
                $element['asset'] = $fileId ?? $standInAssetId;
            } else {
                $element['input'] = array_filter(['name' => $input['name'] ?? null], static fn (mixed $value): bool => $value !== null) + ['placeholder' => true];
                $element['asset'] = $fileId;

                // A fillable slot draws its sample over the whole frame: with
                // "Vejít celý" the frame takes the sample's own ratio.
                if ($fileId !== null && $fit === self::FIT_CONTAIN && isset($pictureSizes[$fileId])) {
                    $element = self::containedFrame($element, $pictureSizes[$fileId]);
                }
            }

            $elements[$index] = $element;
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $element
     * @param array{int, int} $size
     * @return array<string, mixed>
     */
    private static function containedFrame(array $element, array $size): array
    {
        $x = self::number($element['x'] ?? null);
        $y = self::number($element['y'] ?? null);
        $width = self::number($element['width'] ?? null);
        $height = self::number($element['height'] ?? null);

        if ($x === null || $y === null || $width === null || $height === null || $width <= 0.0 || $height <= 0.0 || $size[0] < 1 || $size[1] < 1) {
            return $element;
        }

        $factor = min($width / $size[0], $height / $size[1]);
        $fittedWidth = $size[0] * $factor;
        $fittedHeight = $size[1] * $factor;

        $element['x'] = round($x + ($width - $fittedWidth) / 2, 2);
        $element['y'] = round($y + ($height - $fittedHeight) / 2, 2);
        $element['width'] = round($fittedWidth, 2);
        $element['height'] = round($fittedHeight, 2);

        return $element;
    }

    public static function colour(mixed $value): null|string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function pageColour(array $report, string $default = '#ffffff'): string
    {
        foreach (is_array($report['originalColours'] ?? null) ? $report['originalColours'] : [] as $colour) {
            $hex = self::colour(is_array($colour) ? ($colour['hex'] ?? null) : $colour);

            if ($hex !== null) {
                return $hex;
            }
        }

        return $default;
    }
}
