<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Exceptions\InvalidRichTextValue;

/**
 * An API render body with its `rows` (repeatable container rows) folded into
 * the ordinary value maps — what {@see \WBoost\Web\Services\Template\ApiRepeatableRows::apply()}
 * returns. Row k's values sit under the rows' slot ids in `inputs` /
 * `images` / `shapeColors`, exactly where the web fill keeps them, and
 * `counts` holds the row count of every row the body sent (absent rows render
 * their default).
 *
 * `origins` remembers which slot id came from which row entry, so a value
 * refused further down (`value_too_long`, `image_not_allowed`, …) is reported
 * against the id the caller SENT plus `rowContainerId` / `rowIndex`
 * ({@see self::tag()}).
 */
final readonly class AppliedApiRows
{
    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $images
     * @param array<string, mixed> $shapeColors
     * @param array<string, int> $counts row container id → the sent row count
     * @param array<string, array{string, int, string}> $origins slot id → [row container id, row index, template member id]
     */
    public function __construct(
        public array $inputs,
        public array $images,
        public array $shapeColors,
        public array $counts = [],
        public array $origins = [],
    ) {
    }

    /**
     * The same body with its image map replaced (a group dimension layers its
     * own `placements` over the shared picks AFTER the rows were applied, so
     * a placement may address a row's slot id).
     *
     * @param array<string, mixed> $images
     */
    public function withImages(array $images): self
    {
        return new self($this->inputs, $images, $this->shapeColors, $this->counts, $this->origins);
    }

    /**
     * A refusal of a value that came through `rows`, re-tagged with the row:
     * the id field holds the template member id the caller sent and
     * `rowContainerId` + `rowIndex` say which entry it was.
     */
    public function tag(InvalidExportValue|InvalidRichTextValue $error): InvalidExportValue|InvalidRichTextValue
    {
        foreach (['inputId', 'imageInputId', 'shapeInputId', 'containerId'] as $key) {
            $id = $error->context[$key] ?? null;

            if (is_string($id) && isset($this->origins[$id])) {
                [$rowId, $rowIndex, $templateId] = $this->origins[$id];

                return $error->withContext([$key => $templateId, 'rowContainerId' => $rowId, 'rowIndex' => $rowIndex]);
            }
        }

        return $error;
    }
}
