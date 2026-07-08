<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\RichTextOptions;

/**
 * Shared engine of the user-fill / export page, used by both canvas template
 * modules (SocialNetwork:VariantFiller and CustomTemplate:VariantFiller) so fill-page
 * behaviour evolves in one place.
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
    ) {
    }

    /**
     * The hydrated variant, or null before hydration (see the subclass
     * LiveProp docblocks).
     */
    abstract protected function nullableVariant(): null|SocialNetworkTemplateVariant|CustomTemplateVariant;

    /**
     * The module's VIEW voter attribute for the variant entity.
     */
    abstract protected function viewAttribute(): string;

    /**
     * The plain form POST target producing the PNG download.
     */
    abstract public function downloadPath(): string;

    /**
     * The session-authed placeholder upload endpoint for one image slot.
     */
    abstract public function uploadPath(string $inputId): string;

    protected function variantEntity(): SocialNetworkTemplateVariant|CustomTemplateVariant
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

            $this->textValues[$input->inputId] ??= '';

            if ($input->hidable) {
                $this->hiddenValues[$input->inputId] ??= false;
            }
        }
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
     * The interactive canvas backdrop: the server render with every image
     * placeholder HIDDEN, so the live Fabric image objects the user positions
     * are the only pictures shown in those slots. Re-rendered on each text edit
     * (Live re-render) and picked up by the fill controller.
     */
    public function backdropDataUri(): string
    {
        $variant = $this->variantEntity();

        $hidden = [];
        foreach ($variant->imageInputs as $input) {
            $hidden[$input->inputId] = true;
        }

        return $this->renderToDataUri(new ResolvedImageOverrides([], $hidden));
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
     *     images: list<array{id: string, url: string}>,
     *     directories: list<array{id: string, name: string}>,
     *     includesRoot: bool,
     *     canUpload: bool
     * }>
     */
    public function imagePlaceholders(): array
    {
        $variant = $this->variantEntity();
        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);
        $project = $variant->template->project;

        $result = [];
        foreach ($variant->imageInputs as $input) {
            $object = $objects[$input->inputId] ?? null;

            $frame = null;
            $defaultImageUrl = null;
            if ($object !== null) {
                $placeholderFrame = $this->placeholderGeometry->frameFromObject($object);
                if ($placeholderFrame !== null) {
                    $frame = [
                        'x' => $placeholderFrame->x,
                        'y' => $placeholderFrame->y,
                        'width' => $placeholderFrame->width,
                        'height' => $placeholderFrame->height,
                    ];
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
     *     frame: null|array{x: float, y: float, width: float, height: float},
     *     value: string,
     *     runs: null|list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>,
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
                'frame' => $frame,
                'value' => $this->textValues[$input->inputId] ?? '',
                'runs' => $this->seededRuns($input),
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
                'inputId' => $input->inputId,
                'label' => $name !== '' ? $name : sprintf('Obrázek %d', $position + 1),
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
        if (!$input->richText) {
            return null;
        }

        $storedValue = $this->textValues[$input->inputId] ?? '';
        $envelopeRuns = RichText::tryExtractEnvelopeRuns($storedValue);

        if ($envelopeRuns !== null) {
            return RichText::fromRaw($envelopeRuns, strict: false, inputLabel: $input->name ?? $input->inputId)->toArray();
        }

        if ($storedValue === '') {
            return [];
        }

        return [['text' => $storedValue, 'fontFamily' => null, 'color' => null, 'underline' => false]];
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

        /** @var array<string, list<array{family: string, faceName: string}>> $grouped */
        $grouped = [];
        foreach ($options->fonts as $font) {
            $grouped[$font->fontName][] = ['family' => $font->family, 'faceName' => $font->faceName];
        }

        $fontGroups = [];
        foreach ($grouped as $name => $faces) {
            $fontGroups[] = ['name' => $name, 'faces' => $faces];
        }

        return [...$options->toArray(), 'fontGroups' => $fontGroups];
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
                    'family' => sprintf('%s (%s)', $font->name, $fontFace->name),
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
     *         richText: bool
     *     }>,
     *     containers: list<array{id: string, maxHeight: float, memberInputIds: list<string>}>
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
            ];
        }

        return [
            'inputs' => $inputs,
            'containers' => array_map(
                static fn (CanvasContainer $container): array => $container->toArray(),
                CanvasContainer::collectionFromCanvas($canvas),
            ),
        ];
    }

    private function renderToDataUri(ResolvedImageOverrides $imageOverrides): string
    {
        $variant = $this->variantEntity();
        $this->denyAccessUnlessGranted($this->viewAttribute(), $variant);

        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            $this->buildProvidedValues(),
            truncateOverflow: true,
            richTextOptions: $this->richTextOptions(),
        );
        $bytes = $this->renderer->renderToBytes($variant, $overrides, $imageOverrides);

        if ($bytes === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    /**
     * @param list<FileDirectory> $directories the slot's effective allowed folders
     * @return list<array{id: string, url: string}>
     */
    private function allowedImages(UuidInterface $projectId, array $directories, bool $includeRoot): array
    {
        $directoryIds = array_map(static fn (FileDirectory $directory): UuidInterface => $directory->id, $directories);

        return array_map(
            fn (FileUpload $file): array => [
                'id' => $file->id->toString(),
                'url' => $this->uploaderHelper->getPublicPath($file->path),
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
