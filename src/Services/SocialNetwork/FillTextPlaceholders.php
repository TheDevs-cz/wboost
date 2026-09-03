<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\CanvasShape;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedListStyle;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\RichTextFontOption;
use WBoost\Web\Value\RichTextOptions;

/**
 * The per-text-input presentation of a variant's fill surface — what the
 * click-into-preview overlay, its popovers and the "Vrstvy" panel are built
 * from. ONE implementation for both fill pages: the single-variant Live
 * component ({@see \WBoost\Web\Twig\Components\AbstractVariantFiller}) and
 * the group fill page ({@see \WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders}),
 * which unifies the placeholders of every member dimension over it. The two
 * pages used to disagree on what a text input could do (the group page
 * offered a bare single-line field, so a rich input on a synchronized
 * template had no WYSIWYG at all); building both from here is what keeps
 * them identical.
 *
 * Everything is derived from the variant + the user's current fill state —
 * nothing here reads the request or the session.
 */
final readonly class FillTextPlaceholders
{
    public function __construct(
        private TextInputObjectBinder $textInputObjectBinder,
        private ResolveRichTextOptions $resolveRichTextOptions,
    ) {
    }

    /**
     * The rich-text options object whenever ANY fillable input can switch
     * fonts (rich, or a plain input the designer opened up) — the popovers'
     * face menus come from it and the render validates the user's pick
     * against it. Null when no input needs it (the resolver hits the fonts +
     * manuals queries, so callers memoize the result per request).
     */
    public function fontOptions(TemplateVariant $variant): null|RichTextOptions
    {
        foreach ($variant->inputs as $input) {
            if (!$input->locked && ($input->richText || $input->offersFontChoice())) {
                return $this->resolveRichTextOptions->forVariant($variant);
            }
        }

        return null;
    }

    /**
     * The WYSIWYG toolbar payload (pickable faces + brand colour swatches),
     * or null when the variant has no fillable rich input — the template then
     * skips the WYSIWYG chrome entirely.
     *
     * @return null|array{
     *     fonts: list<array{family: string, fontName: string, faceName: string, weight: int, style: string, url: string}>,
     *     colors: list<string>,
     *     fontGroups: list<array{name: string, faces: list<array{family: string, faceName: string}>}>
     * }
     */
    public function richTextToolbar(TemplateVariant $variant, null|RichTextOptions $fontOptions): null|array
    {
        if ($fontOptions === null) {
            return null;
        }

        foreach ($variant->inputs as $input) {
            if ($input->richText && !$input->locked) {
                return $fontOptions->toToolbarArray();
            }
        }

        return null;
    }

    /**
     * Per-text-input data for the fill overlay: the placeholder's frame (for
     * the highlight border + the inline editing affordance anchored over the
     * preview) plus the input rules and the user's current value / hide flag.
     * `frame` is null when the textbox can't be located on the canvas, in
     * which case the overlay falls back to the flat field list.
     *
     * The value semantics are the fill pages' shared contract: an input the
     * user hasn't touched seeds from the admin's "Vzorový text" (the same
     * value the render falls back to, so the field and the preview agree),
     * and an EMPTY value blanks the text.
     *
     * @param array<string, string> $textValues inputId → the user's (mirror) value
     * @param array<string, bool> $hiddenValues inputId → hidden
     * @param array<string, string> $fontValues inputId → whole-text font pick ("" = designed)
     * @param list<string> $echoCapableIds
     * @return list<array{
     *     inputId: string,
     *     name: null|string,
     *     description: null|string,
     *     maxLength: null|int,
     *     locked: bool,
     *     uppercase: bool,
     *     hidable: bool,
     *     richText: bool,
     *     lists: bool,
     *     listCheckboxes: bool,
     *     checklist: bool,
     *     checklistAdd: bool,
     *     checklistRemove: bool,
     *     checklistEditText: bool,
     *     checklistToggle: bool,
     *     checklistItems: null|list<array{text: string, checked: bool}>,
     *     frame: null|array{x: float, y: float, width: float, height: float},
     *     value: string,
     *     runs: null|list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>,
     *     lines: null|list<string>,
     *     designFontFamily: null|string,
     *     fontOptions: list<array{family: string, fontName: string, faceName: string, weight: int, style: string, url: string}>,
     *     fontGroups: list<array{name: string, faces: list<array{family: string, faceName: string}>}>,
     *     fontChoice: bool,
     *     fontDefaultLabel: string,
     *     fontValue: string,
     *     colorOptions: null|list<string>,
     *     textAlign: string,
     *     hidden: bool,
     *     echoCapable: bool
     * }>
     */
    public function placeholders(
        TemplateVariant $variant,
        array $textValues,
        array $hiddenValues,
        array $fontValues,
        array $echoCapableIds,
        null|RichTextOptions $fontOptions,
    ): array {
        $canvas = self::decodeCanvas($variant);
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);
        $styles = $this->textInputObjectBinder->textStylesByInputId($canvas, $variant->inputs);

        $result = [];
        foreach ($variant->inputs as $input) {
            $placeholderFrame = $frames[$input->inputId] ?? null;
            $storedValue = $textValues[$input->inputId] ?? $input->sampleValue ?? '';

            // Font options for THIS input: the WYSIWYG's face menu (rich —
            // every option, "" = the designed font) or the whole-text font
            // select (plain, only when the designer opened the input up and at
            // least one extra face resolved — the menu lists the SWITCHABLE
            // faces under a "(výchozí)" entry for the designed one).
            $fontChoice = $fontOptions !== null && !$input->richText && $fontOptions->offersFontSwitch($input);
            $faces = $fontOptions !== null && !$input->locked && $input->richText
                ? $fontOptions->fontOptionsFor($input->inputId)
                : ($fontChoice ? $fontOptions->switchableFontsFor($input->inputId) : []);
            $designedFace = $fontChoice ? $fontOptions->designedFontFor($input->inputId) : null;
            $envelope = $this->seededEnvelope($input, $storedValue);

            $result[] = [
                'inputId' => $input->inputId,
                'name' => $input->name,
                'description' => $input->description,
                'maxLength' => $input->maxLength,
                'locked' => $input->locked,
                'uppercase' => $input->uppercase,
                'hidable' => $input->hidable,
                'richText' => $input->richText,
                'lists' => $input->richText && $input->lists,
                'listCheckboxes' => $input->richText && $input->lists && $input->listCheckboxes,
                // Checklist COMPONENT: the fill page renders the simple
                // per-item editor instead of the WYSIWYG, gated by the four
                // capability flags; items are the current/seeded value.
                'checklist' => $input->checklist,
                'checklistAdd' => $input->checklistAdd,
                'checklistRemove' => $input->checklistRemove,
                'checklistEditText' => $input->checklistEditText,
                'checklistToggle' => $input->checklistToggle,
                'checklistItems' => $input->checklist ? self::checklistItems($envelope) : null,
                'frame' => $placeholderFrame !== null
                    ? [
                        'x' => $placeholderFrame->x,
                        'y' => $placeholderFrame->y,
                        'width' => $placeholderFrame->width,
                        'height' => $placeholderFrame->height,
                    ]
                    : null,
                'value' => $storedValue,
                'runs' => $envelope['runs'] ?? null,
                'lines' => $envelope['lines'] ?? null,
                'designFontFamily' => $styles[$input->inputId]['fontFamily'] ?? null,
                'fontOptions' => array_map(static fn (RichTextFontOption $font): array => $font->toArray(), $faces),
                'fontGroups' => RichTextOptions::groupFaces($faces),
                'fontChoice' => $fontChoice,
                'fontDefaultLabel' => $designedFace !== null ? sprintf('%s (výchozí)', $designedFace->faceName) : 'Výchozí písmo',
                'fontValue' => $fontChoice ? ($fontValues[$input->inputId] ?? '') : '',
                // Rich inputs: the admin's colour allowlist (null = brand
                // swatches + free picker, [] = colour locked, list = only those).
                'colorOptions' => $input->richText ? $input->allowedColors : null,
                'textAlign' => $styles[$input->inputId]['textAlign'] ?? 'left',
                'hidden' => $hiddenValues[$input->inputId] ?? false,
                // Echo-capable inputs get the LAZY settle debounce — the echo
                // covers the gap; everything else keeps the fast one.
                'echoCapable' => in_array($input->inputId, $echoCapableIds, true),
            ];
        }

        return $result;
    }

    /**
     * Combined layers list for the fill page's "Vrstvy" panel: the FILLABLE
     * placeholders only — non-locked text inputs and image slots — ordered by
     * canvas stacking TOPMOST FIRST (Photoshop convention; the canvas objects
     * array order is Fabric's paint order). Locked texts, decorative images
     * and the background are fixed design, shown only in the admin editor's
     * layers panel. `interactive` marks rows that can open an editor: text
     * with a locatable frame (its popover anchors to the overlay box) and any
     * image slot (the gallery modal needs no anchor). `hidden` reflects only
     * the server-known text hide state — image hide is client-side and the
     * panel's eye buttons are kept in sync by the overlay controller.
     *
     * @param array<string, bool> $hiddenValues
     * @return list<array{
     *     kind: 'text'|'image',
     *     icon: string,
     *     inputId: string,
     *     label: string,
     *     hidable: bool,
     *     hidden: bool,
     *     interactive: bool
     * }>
     */
    public function layers(TemplateVariant $variant, array $hiddenValues): array
    {
        $canvas = self::decodeCanvas($variant);
        $layerIndexes = $this->textInputObjectBinder->layerIndexesByInputId($canvas, $variant->inputs);
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);

        $layers = [];

        foreach (array_values($variant->inputs) as $position => $input) {
            if ($input->locked) {
                continue;
            }

            $name = trim($input->name ?? '');
            $layers[] = [
                'kind' => 'text',
                // Checklist components get their own glyph — a "text" icon
                // misrepresents what the row edits.
                'icon' => $input->checklist ? 'mdi-format-list-checks' : 'mdi-format-text',
                'inputId' => $input->inputId,
                // The generic fallback numbers by the FULL inputs list, so
                // "Text 4" matches the admin editor even with locked inputs
                // filtered out here.
                'label' => $name !== '' ? $name : sprintf('Text %d', $position + 1),
                'hidable' => $input->hidable,
                'hidden' => $hiddenValues[$input->inputId] ?? false,
                'interactive' => isset($frames[$input->inputId]),
            ];
        }

        foreach (array_values($variant->imageInputs) as $position => $input) {
            $name = trim($input->name ?? '');
            $layers[] = [
                'kind' => 'image',
                'icon' => 'mdi-image-outline',
                'inputId' => $input->inputId,
                'label' => $name !== '' ? $name : ($input->isBackground ? 'Pozadí' : sprintf('Obrázek %d', $position + 1)),
                'hidable' => $input->hidable,
                'hidden' => false,
                'interactive' => true,
            ];
        }

        // Topmost first; placeholders whose object can't be located sink to
        // the end (usort is stable, so they keep their definition order).
        usort($layers, static function (array $a, array $b) use ($layerIndexes): int {
            $aIndex = $layerIndexes[$a['inputId']] ?? PHP_INT_MIN;
            $bIndex = $layerIndexes[$b['inputId']] ?? PHP_INT_MIN;

            return $bIndex <=> $aIndex;
        });

        return $layers;
    }

    /**
     * The overlay's client-side reflow payload: per-input designed frame +
     * text-style metrics (what Fabric needs to measure wrapped height) and the
     * variant's container definitions. The overlay runs the same shared
     * container_layout.js algorithm the headless render runs, so the boxes it
     * draws track exactly where the server render put the text.
     *
     * @return array{
     *     inputs: array<string, array{
     *         frame: null|array{x: float, y: float, width: float, height: float},
     *         style: null|array{fontFamily: string, fontSize: float, lineHeight: float, charSpacing: float, textAlign: string},
     *         locked: bool,
     *         uppercase: bool,
     *         maxLength: null|int,
     *         hidable: bool,
     *         richText: bool,
     *         lists: bool,
     *         listStyle: null|array{bullet: string, bulletImage: null|string, indent: float, itemSpacing: float, blockSpacing: float, checkboxes: bool, checkboxImage: null|string, checkboxCheckedImage: null|string}
     *     }>,
     *     decorations: array<string, array{frame: array{x: float, y: float, width: float, height: float}}>,
     *     containers: list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap: null|float, spaceAfter: null|float}>,
     *     canvasHeight: int
     * }
     */
    public function layoutData(TemplateVariant $variant): array
    {
        $canvas = self::decodeCanvas($variant);
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);
        $styles = $this->textInputObjectBinder->textStylesByInputId($canvas, $variant->inputs);
        $containers = CanvasContainer::collectionFromCanvas($canvas);

        $inputs = [];
        foreach ($variant->inputs as $input) {
            $placeholderFrame = $frames[$input->inputId] ?? null;
            $inputs[$input->inputId] = [
                'frame' => $placeholderFrame !== null
                    ? [
                        'x' => $placeholderFrame->x,
                        'y' => $placeholderFrame->y,
                        'width' => $placeholderFrame->width,
                        'height' => $placeholderFrame->height,
                    ]
                    : null,
                'style' => $styles[$input->inputId] ?? null,
                'locked' => $input->locked,
                'uppercase' => $input->uppercase,
                'maxLength' => $input->maxLength,
                'hidable' => $input->hidable,
                'richText' => $input->richText,
                // Lists change the measured height (block stack instead of a
                // single wrapped textbox) — the overlay needs the RESOLVED
                // spacing config to mirror the server's stack layout.
                'lists' => $input->richText && $input->lists,
                'listStyle' => $input->richText && $input->lists
                    ? ResolvedListStyle::resolve(
                        $input,
                        fontSize: (float) ($styles[$input->inputId]['fontSize'] ?? 40),
                        lineHeight: (float) ($styles[$input->inputId]['lineHeight'] ?? 1.16),
                    )->toArray()
                    : null,
            ];
        }

        return [
            'inputs' => $inputs,
            'decorations' => self::decorativeMemberFrames($canvas, $containers),
            'containers' => array_map(
                static fn (CanvasContainer $container): array => $container->toArray(),
                $containers,
            ),
            'canvasHeight' => $variant->dimension->height(),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeCanvas(TemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Seed for a rich input's WYSIWYG: runs + the per-line list types (null
     * while the stored value carries no lists), parsed from the stored mirror
     * value. The mirror may hold either the `{"runs":[...]}` envelope the
     * editor writes, or plain text (fresh state / no-JS entry) — raw JSON
     * must never leak into a visible editing surface. Null for non-rich
     * inputs.
     *
     * @return null|array{runs: list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>, lines: null|list<string>}
     */
    private function seededEnvelope(EditorTextInput $input, string $storedValue): null|array
    {
        if (!$input->richText) {
            return null;
        }

        $envelope = RichText::tryExtractEnvelope($storedValue);

        if ($envelope !== null) {
            return RichText::fromRaw(
                $envelope['runs'],
                strict: false,
                inputLabel: $input->name ?? $input->inputId,
                rawLines: $envelope['lines'],
                listsAllowed: $input->lists,
                checkboxesAllowed: $input->lists && $input->listCheckboxes,
            )->toEnvelopeArray();
        }

        if ($storedValue === '') {
            return ['runs' => [], 'lines' => null];
        }

        return [
            'runs' => [['text' => $storedValue, 'fontFamily' => null, 'color' => null, 'underline' => false]],
            'lines' => null,
        ];
    }

    /**
     * Per-item seed for a CHECKLIST component's fill editor, derived from the
     * current/seeded value: one entry per line, checked = 'cbx' line type.
     *
     * @param null|array{runs: list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>, lines: null|list<string>} $envelope
     * @return list<array{text: string, checked: bool}>
     */
    private static function checklistItems(null|array $envelope): array
    {
        $envelope ??= ['runs' => [], 'lines' => null];
        $plain = implode('', array_map(
            static fn (array $run): string => $run['text'],
            $envelope['runs'],
        ));

        if ($plain === '') {
            return [];
        }

        $items = [];
        foreach (explode("\n", $plain) as $index => $text) {
            $items[] = [
                'text' => $text,
                'checked' => ($envelope['lines'][$index] ?? 'cb') === 'cbx',
            ];
        }

        return $items;
    }

    /**
     * Designed frames of DECORATIVE members — images (checklist icons,
     * separators) and vector shapes (rules, bullets, colour blocks) — which the
     * overlay feeds to the shared layout engine as geometry POJOs so its
     * client-side reflow sees exactly the members the server render does.
     * Fillable placeholders and the background layer are never container
     * members; design-hidden objects are skipped like the engine skips them.
     *
     * @param array<array-key, mixed> $canvas
     * @param list<CanvasContainer> $containers
     * @return array<string, array{frame: array{x: float, y: float, width: float, height: float}}>
     */
    private static function decorativeMemberFrames(array $canvas, array $containers): array
    {
        $memberIds = [];
        foreach ($containers as $container) {
            foreach ($container->memberInputIds as $memberId) {
                $memberIds[$memberId] = true;
            }
        }
        if ($memberIds === []) {
            return [];
        }

        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return [];
        }

        $decorations = [];
        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }
            $inputId = $object['inputId'] ?? null;
            $type = $object['type'] ?? null;
            if (!is_string($inputId) || !isset($memberIds[$inputId]) || !is_string($type)) {
                continue;
            }
            // Decorative container members: images and vector shapes. Shapes are
            // decorative by definition, so only the image branch has anything to
            // exclude (see isDecorationObject in container_layout.js).
            $isShape = CanvasShape::isShapeType($type);
            if (!$isShape && strtolower($type) !== 'image') {
                continue;
            }
            if (!$isShape
                && (($object['imagePlaceholder'] ?? false) === true || ($object['isBackground'] ?? false) === true)
            ) {
                continue;
            }
            if (($object['visible'] ?? true) === false) {
                continue;
            }

            $left = $object['left'] ?? null;
            $top = $object['top'] ?? null;
            $width = $object['width'] ?? null;
            $height = $object['height'] ?? null;
            if (!is_numeric($left) || !is_numeric($top) || !is_numeric($width) || !is_numeric($height)) {
                continue;
            }
            $scaleX = is_numeric($object['scaleX'] ?? null) ? (float) $object['scaleX'] : 1.0;
            $scaleY = is_numeric($object['scaleY'] ?? null) ? (float) $object['scaleY'] : 1.0;

            $decorations[$inputId] = [
                'frame' => [
                    'x' => (float) $left,
                    'y' => (float) $top,
                    'width' => (float) $width * $scaleX,
                    'height' => (float) $height * $scaleY,
                ],
            ];
        }

        return $decorations;
    }
}
