<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\SocialNetwork\ResolveElementOffsets;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Entity\TemplateVariant;

/**
 * The ONE translation between a document's fill form and its pages.
 *
 * The fill machinery (overlay, popovers, mirrors, echo) keys everything by
 * inputId globally — but two pages may carry the same inputId (a duplicated
 * template copies its canvas verbatim) and must NOT share a value. So on a
 * document fill page every `(page, inputId)` gets a FILL KEY:
 *
 * - bound to a shared field → the field's id (every bound box then edits one
 *   mirror — sharing for free);
 * - otherwise → uuid5(page templateId, inputId): deterministic, UUID-shaped
 *   (the client code expects UUIDs), stable across reloads and
 *   detach + re-attach.
 *
 * Rendering never sees fill keys: {@see valuesForPage()} turns the form's
 * fill-key maps back into ONE page's real-inputId maps. Stored versions use
 * the API shape (real ids), never fill keys — a key flips between uuid5 and
 * the field id when an input is (un)bound.
 */
final readonly class DocumentFillKeys
{
    /**
     * @param array<string, array<string, string>> $bound templateId → inputId → fieldId
     */
    private function __construct(
        private array $bound,
    ) {
    }

    public static function forDocument(TemplateDocument $document): self
    {
        $bound = [];

        foreach ($document->fields() as $field) {
            foreach ($field->bindings() as $binding) {
                $bound[$binding->templateId][$binding->inputId] = $field->id->toString();
            }
        }

        return new self($bound);
    }

    public function keyFor(string $templateId, string $inputId): string
    {
        return $this->bound[$templateId][$inputId]
            ?? Uuid::uuid5($templateId, $inputId)->toString();
    }

    public function fieldIdFor(string $templateId, string $inputId): null|string
    {
        return $this->bound[$templateId][$inputId] ?? null;
    }

    /**
     * fill key → real inputId, for every input of the page.
     *
     * @return array<string, string>
     */
    public function inverseForPage(TemplateDocumentPage $page): array
    {
        $templateId = $page->template->id->toString();
        $inverse = [];

        foreach (self::pageInputIds($page->variant) as $inputId) {
            $inverse[$this->keyFor($templateId, $inputId)] = $inputId;
        }

        return $inverse;
    }

    /**
     * The fill form's maps (fill-key space) → ONE page's maps (real inputIds).
     * Flat maps (`textValues`, `hiddenValues`, `fontValues`, `images`,
     * `shapeColorValues`, `rowCounts`) are keyed by fill key; `imagePlacements`
     * and `offsets` (the user's element moves — per page, never shared) are
     * `[variantId][fillKey]` (page variants are unique, so the outer key stays).
     * Keys of other pages simply don't translate and are dropped.
     *
     * @param array<string, array<array-key, mixed>> $raw
     *
     * @return array{textValues: array<string, mixed>, hiddenValues: array<string, mixed>, fontValues: array<string, mixed>, images: array<string, mixed>, shapeColorValues: array<string, mixed>, rowCounts: array<string, mixed>, imagePlacements: array<string, array<string, mixed>>, offsets: array<string, array<string, mixed>>}
     */
    public function valuesForPage(TemplateDocumentPage $page, array $raw): array
    {
        $inverse = $this->inverseForPage($page);
        $result = [
            'textValues' => [],
            'hiddenValues' => [],
            'fontValues' => [],
            'images' => [],
            'shapeColorValues' => [],
            'rowCounts' => [],
            'imagePlacements' => [],
            'offsets' => [],
        ];

        foreach (['textValues', 'hiddenValues', 'fontValues', 'images', 'shapeColorValues', 'rowCounts'] as $name) {
            foreach ($raw[$name] ?? [] as $key => $value) {
                $inputId = $inverse[(string) $key] ?? null;

                if ($inputId !== null) {
                    $result[$name][$inputId] = $value;
                }
            }
        }

        $variantId = $page->variant->id->toString();

        foreach (['imagePlacements', 'offsets'] as $name) {
            $perVariant = $raw[$name][$variantId] ?? null;

            if (!is_array($perVariant)) {
                continue;
            }

            foreach ($perVariant as $key => $value) {
                $inputId = $inverse[(string) $key] ?? null;

                if ($inputId !== null) {
                    $result[$name][$variantId][$inputId] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Every input id of a variant: text inputs, image slots, recolourable
     * shapes and HIDABLE containers (their `hiddenValues[<id>]` toggle is a
     * per-page fill key like any input's hide), plus every USER-MOVABLE
     * element (a decorative image, a plain shape, a top-level container —
     * `offsets[<variantId>][<fillKey>]` addresses them by fill key too).
     *
     * @return list<string>
     */
    public static function pageInputIds(TemplateVariant $variant): array
    {
        // Repeatable rows: every materialised row's derived ids are fill
        // keys too, and so is each repeatable row container (its
        // `rowCounts[…]` count is per page).
        $variant = RepeatableRows::expand($variant);
        $ids = [];

        foreach ($variant->inputs as $input) {
            $ids[] = $input->inputId;
        }

        foreach ($variant->imageInputs as $input) {
            $ids[] = $input->inputId;
        }

        foreach (ShapeColorInput::collectionFromCanvasJson($variant->canvas) as $input) {
            $ids[] = $input->inputId;
        }

        $decoded = json_decode($variant->canvas, true);
        foreach (is_array($decoded) ? CanvasContainer::collectionFromCanvas($decoded) : [] as $container) {
            if ($container->hidable || $container->repeat !== null) {
                $ids[] = $container->id;
            }
        }

        foreach (ResolveElementOffsets::movableElementsOf($variant) as $element) {
            $ids[] = $element->id;
        }

        return array_values(array_unique($ids));
    }
}
