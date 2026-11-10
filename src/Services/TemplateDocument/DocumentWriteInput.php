<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Exceptions\InvalidPrintSetup;
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

    /**
     * `{unit, unitWidth, unitHeight, preset?, bleedMm?, cropMarks?}` — a preset
     * wins over the unit size. An omitted `bleedMm` is the default for a new
     * print format ({@see TemplateDimension::DEFAULT_PRINT_BLEED_MM}), an
     * omitted `cropMarks` is no marks; a screen format has neither.
     *
     * @param array<array-key, mixed> $data
     */
    public static function dimension(array $data): TemplateDimension
    {
        $preset = is_string($data['preset'] ?? null) ? DimensionPreset::tryFrom($data['preset']) : null;

        if ($preset !== null && !$preset->isOffered()) {
            throw new \InvalidArgumentException(sprintf('The "%s" preset is no longer offered for new formats — send {unit: "px", unitWidth: %d, unitHeight: %d} for a square page.', $preset->value, $preset->width(), $preset->height()));
        }

        if ($preset !== null) {
            $dimension = TemplateDimension::fromPreset($preset);
            self::printSettings($dimension, $data['bleedMm'] ?? 0.0, $data['cropMarks'] ?? false);

            return $dimension;
        }

        $unit = is_string($data['unit'] ?? null) ? DimensionUnit::tryFrom($data['unit']) : null;
        $width = $data['unitWidth'] ?? null;
        $height = $data['unitHeight'] ?? null;

        if ($unit === null || !is_numeric($width) || !is_numeric($height) || (float) $width <= 0 || (float) $height <= 0) {
            throw new \InvalidArgumentException('"dimension" needs unit (px|mm|cm) + positive unitWidth / unitHeight, or a preset (4:5, 9:16).');
        }

        $plain = new TemplateDimension($unit, (float) $width, (float) $height);
        [$bleed, $cropMarks] = self::printSettings(
            $plain,
            array_key_exists('bleedMm', $data) ? $data['bleedMm'] : ($plain->isPrintFormat() ? TemplateDimension::DEFAULT_PRINT_BLEED_MM : 0.0),
            array_key_exists('cropMarks', $data) ? $data['cropMarks'] : false,
        );
        $dimension = $plain->withPrintSettings($bleed, $cropMarks);

        foreach ([$dimension->width(), $dimension->height()] as $pixels) {
            if ($pixels < self::MIN_CANVAS_PIXELS || $pixels > self::MAX_CANVAS_PIXELS) {
                throw new \InvalidArgumentException(sprintf('The page size must be %d–%d canvas px per side (mm / cm at 300 DPI).', self::MIN_CANVAS_PIXELS, self::MAX_CANVAS_PIXELS));
            }
        }

        return $dimension;
    }

    /**
     * Validates a print setup for `$dimension` — the ONE rule both the REST
     * API and MCP apply on create and update: `bleedMm` a number in the web
     * form's 0–{@see TemplateDimension::MAX_PRINT_BLEED_MM} mm (refused, never clamped), `cropMarks` a
     * boolean, and a screen format (px / preset) accepts only "none"
     * (0 / false), so a caller cannot believe a social post got a bleed.
     * `null` means none.
     *
     * @return array{float, bool}
     *
     * @throws InvalidPrintSetup
     */
    public static function printSettings(TemplateDimension $dimension, mixed $bleedMm, mixed $cropMarks): array
    {
        if ($bleedMm !== null && (!is_int($bleedMm) && !is_float($bleedMm) || $bleedMm < 0 || $bleedMm > TemplateDimension::MAX_PRINT_BLEED_MM)) {
            throw new InvalidPrintSetup(sprintf('"bleedMm" must be a number of millimetres from 0 to %d (got %s).', TemplateDimension::MAX_PRINT_BLEED_MM, json_encode($bleedMm) ?: '?'), 'invalid_bleed');
        }

        if ($cropMarks !== null && !is_bool($cropMarks)) {
            throw new InvalidPrintSetup(sprintf('"cropMarks" must be true or false (got %s).', json_encode($cropMarks) ?: '?'), 'invalid_crop_marks');
        }

        $bleed = (float) ($bleedMm ?? 0.0);
        $marks = $cropMarks ?? false;

        if (!$dimension->isPrintFormat() && ($bleed > 0.0 || $marks)) {
            throw new InvalidPrintSetup('Bleed and crop marks exist only for print formats (mm / cm); a screen (px / preset) format has no print PDF. Omit bleedMm / cropMarks.', 'print_setup_not_available');
        }

        return [$bleed, $marks];
    }

    /**
     * `[{id?, name, kind: "text"|"image"|"shape", bindings: [{pageId, inputId}]}]` —
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
                throw new \InvalidArgumentException(sprintf('fields[%d].kind must be "text", "image" or "shape".', $index));
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
