<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentField;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Services\SocialNetwork\FillShapePlaceholders;
use WBoost\Web\Services\Template\FillFieldOrder;
use WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders;
use WBoost\Web\Value\DocumentFieldKind;
use WBoost\Web\Value\DocumentFillKeys;
use WBoost\Web\Value\EditorImageInput;

/**
 * The view model of the document fill page (`template_document_fill.html.twig`):
 * every page as a fill SURFACE of the shared group/single fill machinery, all
 * in FILL-KEY space ({@see DocumentFillKeys}).
 *
 * Every builder runs on the page's re-keyed in-memory clone
 * ({@see DocumentPageView::rekeyed()}), so each mirror / popover / box / echo
 * object / image frame carries the fill key: two pages with the same inputId
 * get two distinct fields, and a shared field ("Společné pole") is ONE
 * mirror that every bound box edits — the existing first-wins unification by
 * id IS the sharing. On top of that, shared fields are post-processed:
 *
 * - text: plain text only (no WYSIWYG / font choice — v1), the MIN
 *   `maxLength` of its bindings, hidable only when every binding is;
 * - image: the picker offers the INTERSECTION of the bound slots'
 *   allowed folders; placement stays per page (and only where the page's own
 *   slot is adjustable — flags AND-ed over the bound slots).
 *
 * Each page still enforces its own rules at render time — the post-processing
 * only keeps the form from offering what some page would refuse.
 */
readonly final class DocumentFillPage
{
    /** A folder id that never exists: an empty intersection must stay RESTRICTED (an empty list means "any folder"). */
    private const string NO_FOLDER = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private GroupFillPlaceholders $placeholders,
        private FillShapePlaceholders $shapePlaceholders,
        private FillFieldOrder $fillFieldOrder,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     text_placeholders: list<array<string, mixed>>,
     *     shape_placeholders: list<array<string, mixed>>,
     *     image_inputs: list<array<string, mixed>>,
     *     sections: list<array{title: string, shared: bool, fields: list<array<string, mixed>>}>,
     *     rich_toolbar: null|array<string, mixed>,
     *     placement_slots: list<array{inputId: string, name: null|string, allowMove: bool, allowResize: bool, allowRotate: bool}>,
     *     placement_variants: list<array<string, mixed>>,
     *     has_echo: bool
     * }
     */
    public function build(TemplateDocument $document): array
    {
        $keys = DocumentFillKeys::forDocument($document);
        $projectId = $document->project->id;

        /** @var array<string, TemplateDocumentField> $fieldsById */
        $fieldsById = [];
        foreach ($document->fields() as $field) {
            $fieldsById[$field->id->toString()] = $field;
        }

        $pages = [];
        $clones = [];
        /** @var array<string, array<string, mixed>> $texts fill key → placeholder (first wins) */
        $texts = [];
        /** @var array<string, list<array<string, mixed>>> $textBindings fill key → every page's own placeholder */
        $textBindings = [];
        /** @var array<string, array<string, mixed>> $shapes */
        $shapes = [];
        /** @var array<string, list<array{input: EditorImageInput, defaultImageUrl: null|string, page: TemplateDocumentPage, realInputId: string}>> $images */
        $images = [];
        /** @var list<array{page: TemplateDocumentPage, number: int, keys: list<string>}> $sectionSources */
        $sectionSources = [];

        foreach ($document->pages() as $index => $page) {
            $templateId = $page->template->id->toString();
            $variant = $page->variant;
            $clone = DocumentPageView::rekeyed($variant, $keys, $templateId);
            $clones[] = $clone;
            $inverse = $keys->inverseForPage($page);

            $pageKeys = [];

            foreach ($this->placeholders->textPlaceholders([$clone], [], [], []) as $placeholder) {
                /** @var string $key */
                $key = $placeholder['inputId'];
                $texts[$key] ??= $placeholder;
                $textBindings[$key][] = $placeholder;
                $pageKeys[] = $key;
            }

            $defaultUrls = $this->placeholders->defaultImageUrls($clone);
            foreach ($clone->imageInputs as $input) {
                $images[$input->inputId][] = [
                    'input' => $input,
                    'defaultImageUrl' => $defaultUrls[$input->inputId] ?? null,
                    'page' => $page,
                    'realInputId' => $inverse[$input->inputId] ?? $input->inputId,
                ];
                $pageKeys[] = $input->inputId;
            }

            foreach ($this->shapePlaceholders->placeholders([$clone], []) as $shape) {
                $shapes[$shape['inputId']] ??= $shape;
                $pageKeys[] = $shape['inputId'];
            }

            $layout = $this->placeholders->layoutData($clone);
            $textFrames = [];
            /** @var array<string, array{frame: mixed}> $layoutInputs */
            $layoutInputs = is_array($layout['inputs'] ?? null) ? $layout['inputs'] : [];
            foreach ($layoutInputs as $key => $definition) {
                $textFrames[(string) $key] = $definition['frame'];
            }

            $pages[] = [
                'page' => $page,
                'number' => $index + 1,
                'variant' => $variant,
                'width' => $variant->dimension->width(),
                'height' => $variant->dimension->height(),
                'preview_endpoint' => $this->urlGenerator->generate('template_document_fill_preview', [
                    'documentId' => $document->id->toString(),
                    'pageId' => $page->id->toString(),
                ]),
                'image_frames' => $this->placeholders->imageFrames($clone),
                'echo' => $this->placeholders->echoData($clone),
                'text_layout' => $layout,
                'text_frames' => $textFrames,
                'shape_frames' => $this->shapePlaceholders->frames($clone),
                'keys' => array_values(array_unique($pageKeys)),
            ];

            $sectionSources[] = ['page' => $page, 'number' => $index + 1, 'keys' => array_values(array_unique($pageKeys))];
        }

        // ---- Shared-field post-processing ---------------------------------
        foreach ($texts as $key => $placeholder) {
            $field = $fieldsById[$key] ?? null;
            if ($field === null || $field->kind !== DocumentFieldKind::Text) {
                continue;
            }

            $texts[$key] = self::sharedText($placeholder, $field, $textBindings[$key] ?? []);
        }

        $imageSlots = [];
        $placementSlots = [];
        foreach ($images as $key => $bindings) {
            $field = $fieldsById[$key] ?? null;
            $first = $bindings[0];
            $input = $first['input'];

            if ($field !== null && $field->kind === DocumentFieldKind::Image) {
                $input = self::sharedImageInput($field, $input, array_map(static fn (array $binding): EditorImageInput => $binding['input'], $bindings));
            }

            $slot = $this->placeholders->imageSlot($input, $first['defaultImageUrl'], $projectId);
            $slot['shared'] = $field !== null;
            $slot['pageNumber'] = null;
            foreach ($sectionSources as $source) {
                if ($source['page'] === $first['page']) {
                    $slot['pageNumber'] = $source['number'];
                }
            }
            // A page slot's uploads go through its own variant's existing
            // route with the REAL inputId (the server resolves the target
            // folder from that slot); a shared field's through its first
            // binding — the offered folders are the intersection, so any
            // folder picked here is allowed there too.
            $slot['uploadUrl'] = $this->urlGenerator->generate('template_variant_placeholder_upload', [
                'variantId' => $first['page']->variant->id->toString(),
                'inputId' => $first['realInputId'],
            ]);
            $imageSlots[$key] = $slot;

            if ($input->allowMove || $input->allowResize || $input->allowRotate) {
                $placementSlots[$key] = [
                    'inputId' => $key,
                    'name' => $input->name,
                    'allowMove' => $input->allowMove,
                    'allowResize' => $input->allowResize,
                    'allowRotate' => $input->allowRotate,
                ];
            }
        }

        // ---- Per-page placement (only each page's OWN adjustable slots) ----
        // The group page emits slot × EVERY variant; on a 30-page document
        // that alone could pass max_input_vars, which PHP enforces by
        // silently dropping the excess.
        $placementVariants = [];
        foreach ($pages as $i => $entry) {
            $adjustable = [];
            $frames = [];
            foreach ($entry['keys'] as $key) {
                if (!isset($placementSlots[$key]) || !isset($entry['image_frames'][$key])) {
                    continue;
                }

                $slot = $placementSlots[$key];
                $adjustable[] = [
                    'inputId' => $key,
                    'name' => $slot['name'],
                    'allowMove' => $slot['allowMove'],
                    'allowResize' => $slot['allowResize'],
                    'allowRotate' => $slot['allowRotate'],
                    'fields' => array_merge(
                        $slot['allowResize'] ? ['scale'] : [],
                        $slot['allowRotate'] ? ['rotation'] : [],
                        $slot['allowMove'] ? ['offsetXRatio', 'offsetYRatio'] : [],
                    ),
                ];
                $frames[$key] = $entry['image_frames'][$key];
            }

            $pages[$i]['adjustable'] = $adjustable;
            $placementVariants[] = [
                'variantId' => $entry['variant']->id->toString(),
                'width' => $entry['width'],
                'height' => $entry['height'],
                'label' => sprintf('Strana %d', $entry['number']),
                'frames' => $frames === [] ? new \stdClass() : $frames,
                'echo' => $entry['echo'],
                // Which fill keys live on this page: an edit re-renders only
                // the pages carrying the edited key.
                'keys' => $entry['keys'],
            ];
        }

        return [
            'pages' => $pages,
            'text_placeholders' => array_values($texts),
            'shape_placeholders' => array_values($shapes),
            'image_inputs' => array_values($imageSlots),
            'sections' => $this->sections($document, $fieldsById, $sectionSources, $texts, $imageSlots, $shapes),
            'rich_toolbar' => $this->placeholders->richTextToolbar($clones),
            'placement_slots' => array_values($placementSlots),
            'placement_variants' => $placementVariants,
            'has_echo' => array_reduce($pages, static fn (bool $carry, array $entry): bool => $carry || $entry['echo'] !== null, false),
        ];
    }

    /**
     * "Všechna pole": the shared fields first (in the document's field
     * order), then one section per page in that template's admin-arranged
     * `fillFieldOrder` (stored as real inputIds → mapped to fill keys).
     *
     * @param array<string, TemplateDocumentField> $fieldsById
     * @param list<array{page: TemplateDocumentPage, number: int, keys: list<string>}> $sources
     * @param array<string, array<string, mixed>> $texts
     * @param array<string, array<string, mixed>> $imageSlots
     * @param array<string, array<string, mixed>> $shapes
     *
     * @return list<array{title: string, shared: bool, fields: list<array<string, mixed>>}>
     */
    private function sections(TemplateDocument $document, array $fieldsById, array $sources, array $texts, array $imageSlots, array $shapes): array
    {
        $entry = static function (string $key) use ($texts, $imageSlots, $shapes): null|array {
            if (isset($texts[$key])) {
                return ['inputId' => $key, 'kind' => 'text', 'ph' => $texts[$key]];
            }
            if (isset($imageSlots[$key])) {
                return ['inputId' => $key, 'kind' => 'image', 'slot' => $imageSlots[$key]];
            }
            if (isset($shapes[$key])) {
                return ['inputId' => $key, 'kind' => 'shape', 'ph' => $shapes[$key]];
            }

            return null;
        };

        $sections = [];
        $shared = [];
        foreach ($fieldsById as $key => $field) {
            $item = $entry($key);
            if ($item !== null) {
                $shared[] = $item;
            }
        }

        if ($shared !== []) {
            $sections[] = ['title' => 'Společná pole', 'shared' => true, 'fields' => $shared];
        }

        $keys = DocumentFillKeys::forDocument($document);
        foreach ($sources as $source) {
            $templateId = $source['page']->template->id->toString();
            $fields = [];
            foreach ($source['keys'] as $key) {
                if (isset($fieldsById[$key])) {
                    continue; // listed once, under "Společná pole"
                }
                $item = $entry($key);
                if ($item !== null) {
                    $fields[] = $item;
                }
            }

            $order = array_map(
                static fn (string $inputId): string => $keys->keyFor($templateId, $inputId),
                $source['page']->template->fillFieldOrder,
            );

            $sections[] = [
                'title' => sprintf('Strana %d — %s', $source['number'], $source['page']->template->name),
                'shared' => false,
                'fields' => $this->fillFieldOrder->apply($fields, static fn (array $field): string => $field['inputId'], $order),
            ];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $placeholder the first binding's placeholder
     * @param list<array<string, mixed>> $bindings every bound page's own placeholder
     *
     * @return array<string, mixed>
     */
    private static function sharedText(array $placeholder, TemplateDocumentField $field, array $bindings): array
    {
        $maxLength = null;
        $hidable = $bindings !== [];
        foreach ($bindings as $binding) {
            $limit = $binding['maxLength'] ?? null;
            if (is_int($limit) && ($maxLength === null || $limit < $maxLength)) {
                $maxLength = $limit;
            }
            $hidable = $hidable && ($binding['hidable'] ?? false) === true;
        }

        $value = is_string($placeholder['value'] ?? null) ? $placeholder['value'] : '';

        return [
            ...$placeholder,
            'name' => 'Společné: ' . $field->name,
            'description' => null,
            'maxLength' => $maxLength,
            'value' => $maxLength !== null ? mb_substr($value, 0, $maxLength) : $value,
            'hidable' => $hidable,
            'hidden' => false,
            // A shared value is plain text in v1 (one value for N inputs that
            // may disagree on rich / font settings).
            'richText' => false,
            'lists' => false,
            'listCheckboxes' => false,
            'checklist' => false,
            'checklistItems' => null,
            'runs' => null,
            'lines' => null,
            'fontOptions' => [],
            'fontGroups' => [],
            'fontChoice' => false,
            'fontValue' => '',
            'colorOptions' => null,
            'shared' => true,
        ];
    }

    /**
     * @param list<EditorImageInput> $inputs every bound slot (fill-key space)
     */
    private static function sharedImageInput(TemplateDocumentField $field, EditorImageInput $first, array $inputs): EditorImageInput
    {
        $restricted = array_values(array_filter(
            array_map(static fn (EditorImageInput $input): array => $input->allowedDirectoryIds, $inputs),
            static fn (array $ids): bool => $ids !== [],
        ));

        $allowed = [];
        if ($restricted !== []) {
            $allowed = count($restricted) === 1 ? $restricted[0] : array_values(array_intersect(...$restricted));
            if ($allowed === []) {
                $allowed = [self::NO_FOLDER];
            }
        }

        $all = static fn (\Closure $flag): bool => array_reduce($inputs, static fn (bool $carry, EditorImageInput $input): bool => $carry && $flag($input), true);

        return EditorImageInput::fromArray([
            'name' => 'Společné: ' . $field->name,
            'description' => null,
            'allowedDirectoryIds' => $allowed,
            'allowMove' => $all(static fn (EditorImageInput $input): bool => $input->allowMove),
            'allowResize' => $all(static fn (EditorImageInput $input): bool => $input->allowResize),
            'allowRotate' => $all(static fn (EditorImageInput $input): bool => $input->allowRotate),
            'hidable' => $all(static fn (EditorImageInput $input): bool => $input->hidable),
        ] + $first->toArray());
    }
}
