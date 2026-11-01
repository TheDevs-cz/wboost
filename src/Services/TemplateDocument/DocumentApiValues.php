<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * The API shape of a document fill — `{fields: {fieldId: value}, pages:
 * {pageKey: {inputs, images, shapeColors}}}` with REAL inputIds per page —
 * turned into ONE page's single-variant export body. Shared values override
 * the bound inputs: a text field's TEXT (string, `value` or `runs`) replaces
 * the page's, while the page keeps its own `hide` / `fontFamily` for that
 * input unless the shared value sets them (hide a shared subtitle on one
 * page only, set its face per page); an image
 * field's pick (`imageId` | `{imageId, hide}`) is merged UNDER the page's own
 * `images[inputId]` entry, so a page may carry only its transform for a
 * shared picture.
 */
final readonly class DocumentApiValues
{
    /**
     * @param array<array-key, mixed> $fields fieldId → value
     * @param array<array-key, mixed> $pageEntry `{inputs?, images?, shapeColors?}` of this page
     *
     * @return array{inputs: array<array-key, mixed>, images: array<array-key, mixed>, shapeColors: array<array-key, mixed>}
     */
    public static function forPage(TemplateDocument $document, TemplateDocumentPage $page, array $fields, array $pageEntry): array
    {
        $inputs = is_array($pageEntry['inputs'] ?? null) ? $pageEntry['inputs'] : [];
        $images = is_array($pageEntry['images'] ?? null) ? $pageEntry['images'] : [];
        $shapeColors = is_array($pageEntry['shapeColors'] ?? null) ? $pageEntry['shapeColors'] : [];
        $templateId = $page->template->id->toString();

        foreach ($document->fields() as $field) {
            $fieldId = $field->id->toString();

            if (!array_key_exists($fieldId, $fields)) {
                continue;
            }

            $binding = $field->bindingForTemplate($templateId);

            if ($binding === null) {
                continue;
            }

            $value = $fields[$fieldId];

            if ($field->kind === DocumentFieldKind::Text) {
                $inputs[$binding->inputId] = self::sharedText($value, $inputs[$binding->inputId] ?? null);
                continue;
            }

            if ($field->kind === DocumentFieldKind::Shape) {
                $shapeColors[$binding->inputId] = $value;
                continue;
            }

            $pick = is_array($value) ? $value : ['imageId' => $value];
            $own = $images[$binding->inputId] ?? null;
            $images[$binding->inputId] = is_array($own) ? array_merge($pick, $own, ['imageId' => $pick['imageId'] ?? null]) : $pick;
        }

        return ['inputs' => $inputs, 'images' => $images, 'shapeColors' => $shapeColors];
    }

    /**
     * The shared text over the page's own entry for the bound input: the
     * shared value's text wins; `hide` / `fontFamily` fall back to the page's.
     */
    private static function sharedText(mixed $shared, mixed $own): mixed
    {
        if (!is_array($own)) {
            return $shared;
        }

        $kept = array_intersect_key($own, ['hide' => true, 'fontFamily' => true]);

        if ($kept === []) {
            return $shared;
        }

        $entry = is_array($shared) ? $shared : ['value' => $shared];

        return $entry + $kept;
    }
}
