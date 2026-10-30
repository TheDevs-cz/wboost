<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldDefinition;
use WBoost\Web\Value\DocumentFieldKind;
use WBoost\Web\Value\TemplateDimension;

/**
 * Parsing of the machine-facing document write shapes, shared by the REST API
 * and MCP so both accept exactly the same bodies. Throws
 * \InvalidArgumentException with a consumer-facing English message; the API
 * turns it into a 400, MCP into a tool error.
 */
final class DocumentWriteInput
{
    /** Same bounds as the add-variant form (TemplateVariantFormData). */
    private const int MIN_CANVAS_PIXELS = 16;
    private const int MAX_CANVAS_PIXELS = 10000;
    private const float MAX_BLEED_MM = 20.0;

    /**
     * `{unit, unitWidth, unitHeight, preset?, bleedMm?, cropMarks?}` — a preset
     * wins over the unit size.
     *
     * @param array<array-key, mixed> $data
     */
    public static function dimension(array $data): TemplateDimension
    {
        $preset = is_string($data['preset'] ?? null) ? DimensionPreset::tryFrom($data['preset']) : null;

        if ($preset !== null) {
            return TemplateDimension::fromPreset($preset);
        }

        $unit = is_string($data['unit'] ?? null) ? DimensionUnit::tryFrom($data['unit']) : null;
        $width = $data['unitWidth'] ?? null;
        $height = $data['unitHeight'] ?? null;

        if ($unit === null || !is_numeric($width) || !is_numeric($height) || (float) $width <= 0 || (float) $height <= 0) {
            throw new \InvalidArgumentException('"dimension" needs unit (px|mm|cm) + positive unitWidth / unitHeight, or a preset (1:1, 4:5, 9:16).');
        }

        $bleed = $data['bleedMm'] ?? 0;
        $dimension = (new TemplateDimension($unit, (float) $width, (float) $height))->withPrintSettings(
            is_numeric($bleed) ? max(0.0, min(self::MAX_BLEED_MM, (float) $bleed)) : 0.0,
            ($data['cropMarks'] ?? false) === true,
        );

        foreach ([$dimension->width(), $dimension->height()] as $pixels) {
            if ($pixels < self::MIN_CANVAS_PIXELS || $pixels > self::MAX_CANVAS_PIXELS) {
                throw new \InvalidArgumentException(sprintf('The page size must be %d–%d canvas px per side (mm / cm at 300 DPI).', self::MIN_CANVAS_PIXELS, self::MAX_CANVAS_PIXELS));
            }
        }

        return $dimension;
    }

    /**
     * `[{id?, name, kind: "text"|"image", bindings: [{pageId, inputId}]}]` —
     * `pageId` may also be the page's templateId. Unknown field ids get a
     * fresh id (a new field).
     *
     * @param array<array-key, mixed> $fields
     * @param callable(): UuidInterface $newId
     * @return list<DocumentFieldDefinition>
     */
    public static function fields(TemplateDocument $document, array $fields, callable $newId): array
    {
        $definitions = [];

        foreach (array_values($fields) as $index => $field) {
            if (!is_array($field)) {
                throw new \InvalidArgumentException(sprintf('fields[%d] must be an object.', $index));
            }

            $kind = is_string($field['kind'] ?? null) ? DocumentFieldKind::tryFrom($field['kind']) : null;

            if ($kind === null) {
                throw new \InvalidArgumentException(sprintf('fields[%d].kind must be "text" or "image".', $index));
            }

            $name = is_string($field['name'] ?? null) ? trim($field['name']) : '';

            if ($name === '') {
                throw new \InvalidArgumentException(sprintf('fields[%d].name must not be empty.', $index));
            }

            $bindings = [];
            foreach (is_array($field['bindings'] ?? null) ? array_values($field['bindings']) : [] as $bindingIndex => $binding) {
                $pageId = is_array($binding) ? ($binding['pageId'] ?? null) : null;
                $inputId = is_array($binding) ? ($binding['inputId'] ?? null) : null;

                if (!is_string($pageId) || !Uuid::isValid($pageId) || !is_string($inputId)) {
                    throw new \InvalidArgumentException(sprintf('fields[%d].bindings[%d] needs a pageId (UUID) and an inputId.', $index, $bindingIndex));
                }

                $page = $document->page(Uuid::fromString($pageId)) ?? $document->pageOfTemplate(Uuid::fromString($pageId));

                if ($page === null) {
                    throw new \InvalidArgumentException(sprintf('fields[%d].bindings[%d]: page %s is not in this document.', $index, $bindingIndex, $pageId));
                }

                $bindings[] = new DocumentFieldBinding($page->template->id->toString(), $inputId);
            }

            $id = $field['id'] ?? null;

            $definitions[] = new DocumentFieldDefinition(
                is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : $newId(),
                mb_substr($name, 0, 60),
                $kind,
                $bindings,
            );
        }

        return $definitions;
    }
}
