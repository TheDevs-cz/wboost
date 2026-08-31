<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\CanvasShape;
use WBoost\Web\Value\CanvasSlice;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedListStyle;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\RichTextOptions;

/**
 * Shared engine of the user-fill / export page behind Template:VariantFiller.
 *
 * The text preview is server-rendered via the same Gotenberg pipeline the API
 * uses. The image-placeholder feature layers a HYBRID on top: when the variant
 * has fillable image slots, the page renders an interactive Fabric canvas (the
 * `variant-image-fill` controller) whose backdrop is the server render with the
 * placeholders hidden ({@see backdropDataUri()}); the user's chosen pictures
 * float on top as live Fabric objects. Text still flows through the server
 * backdrop (no client-side fonts). Either way the final download / API export
 * is the full server render, so the produced PNG is authoritative.
 *
 * Subclasses only contribute the module-specific surface: the entity-typed
 * `$variant` LiveProp (Live Components hydrate Doctrine entities by id, so the
 * property must be typed with a concrete entity class), the voter attribute,
 * and the module's download / placeholder-upload routes.
 *
 * Authorisation note: `#[IsGranted]` cannot be applied at class level — the
 * Symfony Security listener resolves the subject from method arguments, and a
 * Live Component's `$variant` is a hydrated LiveProp (class property), not an
 * argument. Access is enforced explicitly in the render methods and
 * `postMount()`, which are the only paths that touch the variant.
 */
abstract class AbstractVariantFiller extends AbstractController
{
    use DefaultActionTrait;

    /**
     * Set when a render was skipped because the renderer is overloaded (see
     * {@see renderToDataUri()}). Deliberately NOT a LiveProp: it describes this
     * one render pass, and the next edit must retry rather than inherit it.
     */
    private bool $renderUnavailable = false;

    /**
     * Whether the current user has a usable Facebook connection (drives the
     * publish buttons vs. the connect CTA). Set by the page controller when
     * the module supports publishing; stays false elsewhere.
     */
    #[LiveProp]
    public bool $facebookConnected = false;

    /**
     * Map of inputId UUID → text value the user has typed.
     *
     * `writable: true` lets Live Components write into any sub-key of this
     * array via `data-model="textValues[<inputId>]"` in the template.
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $textValues = [];

    /**
     * Map of inputId UUID → bool (true = hide). Only inputs whose definition
     * has `hidable: true` honor this; others are silently ignored.
     *
     * @var array<string, bool>
     */
    #[LiveProp(writable: true)]
    public array $hiddenValues = [];

    /**
     * Per-request cache of the variant's rich-text options (fonts + colors) —
     * the resolver hits the fonts + manuals queries, and several render
     * methods need the same options during one request.
     */
    private null|RichTextOptions $richTextOptionsCache = null;

    public function __construct(
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly ResolveRichTextOptions $resolveRichTextOptions,
        private readonly TemplateVariantImageRendererInterface $renderer,
        private readonly CanvasPlaceholderGeometry $placeholderGeometry,
        private readonly TextInputObjectBinder $textInputObjectBinder,
        private readonly PlaceholderAllowedDirectories $allowedDirectories,
        private readonly FileUploadRepository $fileUploadRepository,
        private readonly UploaderHelper $uploaderHelper,
        private readonly GetFonts $getFonts,
        private readonly RequestStack $requestStack,
        private readonly ReleaseSessionLock $releaseSessionLock,
    ) {
    }

    /**
     * The hydrated variant, or null before hydration (see the subclass
     * LiveProp docblocks).
     */
    abstract protected function nullableVariant(): null|TemplateVariant;

    /**
     * The module's VIEW voter attribute for the variant entity.
     */
    abstract protected function viewAttribute(): string;

    /**
     * The plain form POST target producing the PNG download.
     */
    abstract public function downloadPath(): string;

    /**
     * The direct social-publish endpoint (fetch POST, JSON response), or null
     * when the module doesn't support publishing — no publish UI renders.
     */
    public function publishPath(): null|string
    {
        return null;
    }

    /**
     * Whether THIS variant may be published to a social network — the single
     * gate every piece of publish chrome in the shared template checks (along
     * with `facebookConnected` where a connection is required). The default
     * mirrors the module capability (`publishPath()`); subclasses narrow it
     * per variant (a unified Template variant publishes only in a
     * social-format dimension preset).
     */
    public function canPublish(): bool
    {
        return $this->publishPath() !== null;
    }

    /**
     * The session-authed placeholder upload endpoint for one image slot.
     */
    abstract public function uploadPath(string $inputId): string;

    protected function variantEntity(): TemplateVariant
    {
        $variant = $this->nullableVariant();
        assert($variant !== null);

        return $variant;
    }

    /**
     * Pre-populate `textValues` / `hiddenValues` with an entry per non-locked
     * input so the Live Component value-store knows about every inputId key
     * from the first render.
     */
    #[PostMount]
    public function postMount(): void
    {
        $variant = $this->nullableVariant();

        if ($variant === null) {
            return;
        }

        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        foreach ($variant->inputs as $input) {
            if ($input->locked) {
                continue;
            }

            // First render seeds from the admin's "Vzorový text" (the same
            // value the renderer falls back to when an input is omitted), so
            // the field, the preview and an untouched export all agree.
            $this->textValues[$input->inputId] ??= $input->sampleValue ?? '';

            if ($input->hidable) {
                $this->hiddenValues[$input->inputId] ??= false;
            }
        }
    }

    /**
     * Coerce `hiddenValues` back to the booleans its type declares.
     *
     * A writable LiveProp receives the client's RAW value: updates that target
     * a sub-PATH (`hiddenValues[<uuid>]`, which is what the eye button writes)
     * are written straight onto the array with no type coercion — only whole
     * props go through the hydrator. And live_controller's
     * `getValueFromElement()` reads a checkbox that carries a `value`
     * attribute as `element.getAttribute('value')` / `null` — never a bool. So
     * the eye handed us the STRING "1" (or `null` on un-hide), which
     * `ResolveTextOverrides::parseHide()` strictly rejects: every first click
     * on the eye 500'd the Live re-render (Sentry, 2026-08-05).
     *
     * The `value="1"` on the mirror stays — the plain form POST that drives
     * the download needs it — so normalizing here is what keeps the Live path
     * and {@see FillFormRequestParser} (which does the same coercion for the
     * POST) agreeing on the same input.
     */
    #[PostHydrate]
    public function normalizeHiddenValues(): void
    {
        $normalized = [];

        /** @var mixed $hide */
        foreach ($this->hiddenValues as $inputId => $hide) {
            $normalized[$inputId] = $hide === true || $hide === 1 || $hide === '1' || $hide === 'true';
        }

        $this->hiddenValues = $normalized;
    }

    /**
     * True when this pass could not draw a preview because the renderer was
     * busy — the template turns it into a "try again" notice instead of showing
     * a silently blank preview. Call it AFTER the preview/backdrop sources.
     */
    public function renderUnavailable(): bool
    {
        return $this->renderUnavailable;
    }

    public function hasImagePlaceholders(): bool
    {
        return $this->variantEntity()->imageInputs !== [];
    }

    /**
     * The plain server preview (text + background + the designer's stand-in
     * placeholders inlined) for variants WITHOUT fillable image slots. See
     * {@see backdropDataUri()} for the image case.
     */
    public function previewDataUri(): string
    {
        return $this->renderToDataUri(ResolvedImageOverrides::none());
    }

    /**
     * The interactive canvas backdrop: the server render of everything BELOW
     * the lowest image placeholder (background included, placeholders hidden).
     * Content designed above a placeholder is deliberately NOT here — it comes
     * as {@see overlaySlices()} the fill controller stacks OVER the live
     * Fabric objects, so the designed z-order holds on the interactive
     * preview too. Re-rendered on each text edit (Live re-render) and picked
     * up by the fill controller.
     */
    public function backdropDataUri(): string
    {
        $indexes = $this->placeholderStackIndexes();
        $slice = $indexes === []
            ? null
            : new CanvasSlice(0, min($indexes), withBackground: true);

        return $this->renderToDataUri($this->allPlaceholdersHidden(), $slice);
    }

    /**
     * Transparent overlay renders for every placeholder gap that actually
     * holds design content (a locked image over a photo slot, a title text
     * over a hero picture, …). The fill controller paints each one directly
     * above its placeholder's live object. Ordered bottom-up. Empty for the
     * typical "placeholders on top of the design" case — costs no extra
     * renders then.
     *
     * @return list<array{aboveInputId: string, dataUri: string}>
     */
    public function overlaySlices(): array
    {
        $decoded = json_decode($this->variantEntity()->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $canvas['objects'] ?? [];

        $gaps = CanvasSlice::overlayGapsAbovePlaceholders(
            is_array($objects) ? $objects : [],
            $this->placeholderStackIndexes(),
        );

        $result = [];
        foreach ($gaps as $gap) {
            $dataUri = $this->renderToDataUri($this->allPlaceholdersHidden(), $gap['slice']);
            if ($dataUri === '') {
                continue;
            }
            $result[] = ['aboveInputId' => $gap['aboveInputId'], 'dataUri' => $dataUri];
        }

        return $result;
    }

    private function allPlaceholdersHidden(): ResolvedImageOverrides
    {
        $hidden = [];
        foreach ($this->variantEntity()->imageInputs as $input) {
            $hidden[$input->inputId] = true;
        }

        return new ResolvedImageOverrides([], $hidden);
    }

    /**
     * Stack index of every locatable image placeholder object, keyed by
     * inputId — the z-positions the backdrop/overlay slices cut around.
     *
     * @return array<string, int>
     */
    private function placeholderStackIndexes(): array
    {
        $decoded = json_decode($this->variantEntity()->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];

        return $this->placeholderGeometry->placeholderObjectIndexesByInputId($canvas);
    }

    /**
     * Per-placeholder data for the fill controller + picker: the designer's
     * frame, the user limits, the stand-in url, and the gallery images the slot
     * may be filled from (already scoped to the allowed folders).
     *
     * @return list<array{
     *     inputId: string,
     *     name: null|string,
     *     description: null|string,
     *     allowMove: bool,
     *     allowResize: bool,
     *     allowRotate: bool,
     *     hidable: bool,
     *     frame: null|array{x: float, y: float, width: float, height: float},
     *     defaultImageUrl: null|string,
     *     images: list<array{id: string, url: string, directoryId: string}>,
     *     directories: list<array{id: string, name: string}>,
     *     includesRoot: bool,
     *     canUpload: bool,
     *     isBackground: bool,
     *     layerIndex: int
     * }>
     */
    public function imagePlaceholders(): array
    {
        $variant = $this->variantEntity();
        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);
        $stackIndexes = $this->placeholderGeometry->placeholderObjectIndexesByInputId($canvas);
        $project = $variant->template->project;

        $result = [];
        foreach ($variant->imageInputs as $input) {
            $object = $objects[$input->inputId] ?? null;

            $frame = null;
            $defaultImageUrl = null;
            if ($object !== null) {
                if ($input->isBackground) {
                    // Background slot: the frame IS the canvas — the designed
                    // object's cover-fit bbox overflows it and a fill re-covers
                    // the whole canvas anyway.
                    $frame = [
                        'x' => 0.0,
                        'y' => 0.0,
                        'width' => (float) $variant->dimension->width(),
                        'height' => (float) $variant->dimension->height(),
                    ];
                } else {
                    $placeholderFrame = $this->placeholderGeometry->frameFromObject($object);
                    if ($placeholderFrame !== null) {
                        $frame = [
                            'x' => $placeholderFrame->x,
                            'y' => $placeholderFrame->y,
                            'width' => $placeholderFrame->width,
                            'height' => $placeholderFrame->height,
                        ];
                    }
                }
                $defaultImageUrl = $this->defaultImageUrl($object);
            }

            // Effective folders the slot may be filled from. An empty allow-list
            // is UNRESTRICTED: every project folder plus the gallery root. Only a
            // restricted slot whose every folder vanished is a dead end — the
            // template hides the upload field and explains why.
            $directories = $this->allowedDirectories->resolve($input, $project->id);
            $includesRoot = $this->allowedDirectories->includesRoot($input);

            $result[] = [
                'inputId' => $input->inputId,
                'name' => $input->name,
                'description' => $input->description,
                'allowMove' => $input->allowMove,
                'allowResize' => $input->allowResize,
                'allowRotate' => $input->allowRotate,
                'hidable' => $input->hidable,
                'frame' => $frame,
                'defaultImageUrl' => $defaultImageUrl,
                'images' => $this->allowedImages($project->id, $directories, $includesRoot),
                // Upload targets: with several possible targets the user picks one
                // in the UI (the server refuses to guess); a single folder — or
                // the root for unrestricted slots — is resolved server-side.
                'directories' => array_map(
                    static fn (FileDirectory $directory): array => [
                        'id' => $directory->id->toString(),
                        'name' => $directory->name,
                    ],
                    $directories,
                ),
                'includesRoot' => $includesRoot,
                'canUpload' => $directories !== [] || $includesRoot,
                // Fill semantics differ: cover-fit anchored top-left over the
                // whole canvas, no user transform (the limits above are forced
                // false for background slots).
                'isBackground' => $input->isBackground,
                // Designed z-position — the fill controller restacks the live
                // objects (and the overlay slices above them) by this.
                'layerIndex' => $stackIndexes[$input->inputId] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Per-text-input data for the fill overlay: the placeholder's frame (for the
     * highlight border + the inline editing affordance anchored over the
     * preview) plus the input rules and the user's current value / hide flag.
     * Mirrors {@see imagePlaceholders()}. `frame` is null when the textbox can't
     * be located on the canvas, in which case the overlay falls back to the flat
     * field list.
     *
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
     *     textAlign: string,
     *     hidden: bool
     * }>
     */
    public function textPlaceholders(): array
    {
        $variant = $this->variantEntity();
        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);
        $styles = $this->textInputObjectBinder->textStylesByInputId($canvas, $variant->inputs);

        $result = [];
        foreach ($variant->inputs as $input) {
            $placeholderFrame = $frames[$input->inputId] ?? null;
            $frame = $placeholderFrame !== null
                ? [
                    'x' => $placeholderFrame->x,
                    'y' => $placeholderFrame->y,
                    'width' => $placeholderFrame->width,
                    'height' => $placeholderFrame->height,
                ]
                : null;

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
                'checklistItems' => $this->checklistItems($input),
                'frame' => $frame,
                // Prefill: an input the user hasn't touched yet seeds from
                // the admin's "Vzorový text" — the same value the render
                // falls back to, so the field and the preview agree.
                'value' => $this->textValues[$input->inputId] ?? $input->sampleValue ?? '',
                'runs' => $this->seededRuns($input),
                'lines' => $this->seededEnvelope($input)['lines'] ?? null,
                'designFontFamily' => $styles[$input->inputId]['fontFamily'] ?? null,
                'textAlign' => $styles[$input->inputId]['textAlign'] ?? 'left',
                'hidden' => $this->hiddenValues[$input->inputId] ?? false,
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
    public function layers(): array
    {
        $variant = $this->variantEntity();
        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
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
                'hidden' => $this->hiddenValues[$input->inputId] ?? false,
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
     * The runs a rich input's WYSIWYG editor is seeded with, parsed from the
     * stored mirror value. The mirror may hold either the `{"runs":[...]}`
     * envelope the editor writes, or plain text (fresh state / no-JS entry) —
     * raw JSON must never leak into a visible editing surface. Null for
     * non-rich inputs.
     *
     * @return null|list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>
     */
    private function seededRuns(EditorTextInput $input): null|array
    {
        return $this->seededEnvelope($input)['runs'] ?? null;
    }

    /**
     * Per-item seed for a CHECKLIST component's fill editor, derived from the
     * current/seeded value: one entry per line, checked = 'cbx' line type.
     * Null for non-checklist inputs.
     *
     * @return null|list<array{text: string, checked: bool}>
     */
    private function checklistItems(EditorTextInput $input): null|array
    {
        if (!$input->checklist) {
            return null;
        }

        $envelope = $this->seededEnvelope($input) ?? ['runs' => [], 'lines' => null];
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
     * Seed for a rich input's WYSIWYG: runs + the per-line list types (null
     * while the stored value carries no lists).
     *
     * @return null|array{runs: list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>, lines: null|list<string>}
     */
    private function seededEnvelope(EditorTextInput $input): null|array
    {
        if (!$input->richText) {
            return null;
        }

        $storedValue = $this->textValues[$input->inputId] ?? $input->sampleValue ?? '';
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
     * The rich-text toolbar payload (pickable font faces + brand color
     * swatches), or null when the variant has no rich-text input — the
     * template then skips the WYSIWYG chrome entirely. `fontGroups` is the
     * same faces list grouped by family for the <optgroup> dropdown.
     *
     * @return null|array{
     *     fonts: list<array{family: string, fontName: string, faceName: string, weight: int, style: string, url: string}>,
     *     colors: list<string>,
     *     fontGroups: list<array{name: string, faces: list<array{family: string, faceName: string}>}>
     * }
     */
    public function richTextToolbar(): null|array
    {
        $options = $this->richTextOptions();

        if ($options === null) {
            return null;
        }

        return $options->toToolbarArray();
    }

    private function richTextOptions(): null|RichTextOptions
    {
        $variant = $this->variantEntity();

        $hasRichInput = false;
        foreach ($variant->inputs as $input) {
            if ($input->richText && !$input->locked) {
                $hasRichInput = true;
                break;
            }
        }

        if (!$hasRichInput) {
            return null;
        }

        return $this->richTextOptionsCache ??= $this->resolveRichTextOptions->forVariant($variant);
    }

    /**
     * Project font faces for the fill page: @font-face declarations + explicit
     * loading so the overlay's client-side Fabric text measurement (container
     * reflow) uses the exact glyphs the server render does. Family naming
     * matches the renderer / editor convention: "<font> (<face>)".
     *
     * @return list<array{family: string, url: string}>
     */
    public function fontFaces(): array
    {
        $project = $this->variantEntity()->template->project;

        $result = [];
        foreach ($this->getFonts->allForProject($project->id) as $font) {
            foreach ($font->faces as $fontFace) {
                $result[] = [
                    'family' => $font->faceFamily($fontFace),
                    'url' => $this->uploaderHelper->getPublicPath($fontFace->filePath),
                ];
            }
        }

        return $result;
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
    public function textLayoutData(): array
    {
        $variant = $this->variantEntity();
        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
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
            'decorations' => $this->decorativeMemberFrames($canvas, $containers),
            'containers' => array_map(
                static fn (CanvasContainer $container): array => $container->toArray(),
                $containers,
            ),
            'canvasHeight' => $variant->dimension->height(),
        ];
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
    private function decorativeMemberFrames(array $canvas, array $containers): array
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

    private function renderToDataUri(ResolvedImageOverrides $imageOverrides, null|CanvasSlice $slice = null): string
    {
        $variant = $this->variantEntity();
        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        // A re-render pass draws 2-3 Gotenberg renders; auth already
        // happened and nothing in a Live re-render writes session state
        // after this point, so hand the session back before the slow work
        // (see ReleaseSessionLock). Idempotent across the 2-3 calls of one
        // pass (a closed session stays closed).
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $this->releaseSessionLock->release($request);
        }

        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            $this->buildProvidedValues(),
            truncateOverflow: true,
            richTextOptions: $this->richTextOptions(),
        );
        // Background-less slices are the transparent overlay layers: flat, tiny,
        // and PNG measured FASTER for exactly that shape (0.147s vs 0.220s for
        // WebP, saving only ~7KB). Everything that paints a background is the
        // image-rich case where WebP wins twice — 0.292s vs 0.388s and ~10x
        // smaller at social size, 1.00s vs 1.56s and ~14x smaller at A4 print.
        // Payload matters as much as time here: these bytes get base64'd into
        // the Live response 2-3x per keystroke.
        //
        // This is purely a SPEED split, not a fidelity one — lossy previews are
        // an accepted trade-off; every EXPORT path stays lossless PNG.
        //
        // `withBackground` is not a flag invented for this: it is the same
        // predicate that already drives omitBackground() in the renderer, and
        // CanvasSlice::overlayGapsAbovePlaceholders() hardcodes it false for
        // exactly the overlay gaps. So one expression covers all three callers:
        // previewDataUri() (no slice) and backdropDataUri() (withBackground:
        // true) get WebP, overlaySlices() gets PNG.
        $format = ($slice !== null && !$slice->withBackground)
            ? RenderImageFormat::Png
            : RenderImageFormat::Webp;

        try {
            $bytes = $this->renderer->renderToBytes($variant, $overrides, $imageOverrides, slice: $slice, format: $format);
        } catch (TemplateRenderUnavailable) {
            // The renderer is overloaded. A fill page draws 2-3 renders (backdrop
            // + one per overlay slice) on EVERY edit, so letting this bubble
            // would 503 the Live re-render and leave the page stuck on its
            // spinner. Degrade to "no preview this round" instead: the form and
            // its values survive, and the next edit tries again.
            $this->renderUnavailable = true;

            return '';
        }

        if ($bytes === '') {
            return '';
        }

        return $format->dataUriPrefix() . base64_encode($bytes);
    }

    /**
     * `directoryId` ('' = gallery root) drives the picker modal's folder
     * navigation — thumbs are filtered client-side by their folder.
     *
     * @param list<FileDirectory> $directories the slot's effective allowed folders
     * @return list<array{id: string, url: string, directoryId: string}>
     */
    private function allowedImages(UuidInterface $projectId, array $directories, bool $includeRoot): array
    {
        $directoryIds = array_map(static fn (FileDirectory $directory): UuidInterface => $directory->id, $directories);

        return array_map(
            fn (FileUpload $file): array => [
                'id' => $file->id->toString(),
                'url' => $this->uploaderHelper->getPublicPath($file->path),
                'directoryId' => $file->directory?->id->toString() ?? '',
            ],
            $this->fileUploadRepository->listByProjectSourceAndDirectories($projectId, FileSource::ProjectImage, $directoryIds, $includeRoot),
        );
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private function defaultImageUrl(array $object): null|string
    {
        $assetPath = $object['assetPath'] ?? null;
        if (is_string($assetPath) && $assetPath !== '') {
            return $this->uploaderHelper->getPublicPath($assetPath);
        }

        $src = $object['src'] ?? null;
        if (is_string($src) && $src !== '' && !str_starts_with($src, 'data:')) {
            return $src;
        }

        return null;
    }

    /**
     * Merge the two writable LiveProps into the shape ResolveTextOverrides
     * expects: `{ inputId: { value?: string, hide?: bool } }`.
     *
     * @return array<string, array{value?: string, hide?: bool}>
     */
    private function buildProvidedValues(): array
    {
        /** @var array<string, array{value?: string, hide?: bool}> $merged */
        $merged = [];

        foreach ($this->textValues as $inputId => $value) {
            $merged[$inputId] = ['value' => $value];
        }

        foreach ($this->hiddenValues as $inputId => $hide) {
            if (!isset($merged[$inputId])) {
                $merged[$inputId] = [];
            }
            $merged[$inputId]['hide'] = $hide;
        }

        return $merged;
    }
}
