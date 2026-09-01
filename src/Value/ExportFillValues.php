<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The canonical snapshot of one fill — everything a user provided for an
 * export, in the WEB WIRE SHAPE (the fill form's field values), so a stored
 * version can seed the fill pages back verbatim:
 *
 * - `texts`: inputId → the mirror string (plain text OR a rich
 *   `{"runs":…,"lines":…}` envelope, exactly as the mirror smuggles it).
 * - `hidden`: inputIds explicitly hidden (checkbox semantics: presence = hide).
 * - `images`: imageInputId → `{imageId?, scale?, offsetX?, offsetY?,
 *   offsetXRatio?, offsetYRatio?, rotation?, hide?}`.
 * - `placements`: group exports only — variantId → inputId → the
 *   per-dimension placement override (`imagePlacements[…]` fields).
 *
 * The array form is CANONICAL — keys sorted recursively, floats rounded to 4
 * decimals, empty entries dropped — so `hash()` is stable across field order,
 * float noise and repeated round-trips, which is what version deduplication
 * rests on: the same fill always hashes the same.
 *
 * API / MCP exports are converted INTO this shape (rich runs re-encoded as the
 * envelope string) so one history lists every channel's exports uniformly.
 */
final readonly class ExportFillValues
{
    private const array IMAGE_FLOAT_FIELDS = ['scale', 'offsetX', 'offsetY', 'offsetXRatio', 'offsetYRatio', 'rotation'];

    /**
     * @param array<string, string> $texts
     * @param list<string> $hidden
     * @param array<string, array<string, mixed>> $images
     * @param array<string, array<string, array<string, float>>> $placements
     */
    private function __construct(
        public array $texts,
        public array $hidden,
        public array $images,
        public array $placements,
    ) {
    }

    /**
     * The single-variant fill form (download + publish POST). Mirrors
     * FillFormRequestParser semantics — including that an EMPTY text string is
     * kept: on this surface it means "blank the text", which is part of the
     * fill.
     *
     * @param array<array-key, mixed> $rawTexts `textValues[…]`
     * @param array<array-key, mixed> $rawHidden `hiddenValues[…]`
     * @param array<array-key, mixed> $rawImages `images[…]`
     */
    public static function fromVariantWebForm(array $rawTexts, array $rawHidden, array $rawImages): self
    {
        $texts = [];
        foreach ($rawTexts as $inputId => $value) {
            if (is_string($value)) {
                $texts[(string) $inputId] = $value;
            }
        }

        return new self(
            $texts,
            self::hiddenIds($rawHidden),
            self::imageEntries($rawImages),
            [],
        );
    }

    /**
     * The group fill form. Group semantics: an empty text means "keep the
     * designed text" and is NOT part of the fill, so it is dropped (mirrors
     * GroupFillRenderer); placement stays per dimension, separate from the
     * shared picture pick.
     *
     * @param array<array-key, mixed> $rawTexts
     * @param array<array-key, mixed> $rawHidden
     * @param array<array-key, mixed> $rawImages
     * @param array<array-key, mixed> $rawPlacements `imagePlacements[<variantId>][<inputId>][…]`
     */
    public static function fromGroupWebForm(array $rawTexts, array $rawHidden, array $rawImages, array $rawPlacements): self
    {
        $texts = [];
        foreach ($rawTexts as $inputId => $value) {
            if (is_string($value) && $value !== '') {
                $texts[(string) $inputId] = $value;
            }
        }

        $placements = [];
        foreach ($rawPlacements as $variantId => $slots) {
            if (!is_array($slots)) {
                continue;
            }

            foreach ($slots as $inputId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $placement = [];
                foreach (self::IMAGE_FLOAT_FIELDS as $field) {
                    $candidate = $entry[$field] ?? null;
                    if (is_numeric($candidate)) {
                        $placement[$field] = round((float) $candidate, 4);
                    }
                }

                if ($placement !== []) {
                    $placements[(string) $variantId][(string) $inputId] = $placement;
                }
            }
        }

        return new self(
            $texts,
            self::hiddenIds($rawHidden),
            self::imageEntries($rawImages),
            $placements,
        );
    }

    /**
     * The API / MCP export request (`inputs` + `images` maps). Values are
     * re-encoded into the web wire shape: `{value, hide}` unwraps, rich
     * `{runs, lines}` becomes the envelope STRING the web mirrors carry, so a
     * version recorded through the API seeds the web fill page like any other.
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     */
    public static function fromApiRequest(array $inputs, array $images): self
    {
        $texts = [];
        $hidden = [];

        foreach ($inputs as $inputId => $value) {
            $key = (string) $inputId;

            if (is_string($value)) {
                $texts[$key] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if (isset($value['runs']) && is_array($value['runs'])) {
                $envelope = ['runs' => array_values($value['runs'])];
                if (isset($value['lines']) && is_array($value['lines'])) {
                    $envelope['lines'] = array_values($value['lines']);
                }
                $encoded = json_encode($envelope);
                if (is_string($encoded)) {
                    $texts[$key] = $encoded;
                }
            } elseif (is_string($value['value'] ?? null)) {
                $texts[$key] = $value['value'];
            }

            if (($value['hide'] ?? false) === true) {
                $hidden[] = $key;
            }
        }

        return new self($texts, $hidden, self::imageEntries($images), []);
    }

    /**
     * Defensive read of a stored snapshot (the Doctrine type's PHP side).
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $texts = [];
        foreach (is_array($data['texts'] ?? null) ? $data['texts'] : [] as $inputId => $value) {
            if (is_string($value)) {
                $texts[(string) $inputId] = $value;
            }
        }

        $hidden = [];
        foreach (is_array($data['hidden'] ?? null) ? $data['hidden'] : [] as $inputId) {
            if (is_string($inputId)) {
                $hidden[] = $inputId;
            }
        }

        $images = [];
        foreach (is_array($data['images'] ?? null) ? $data['images'] : [] as $inputId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fields = [];
            foreach ($entry as $field => $value) {
                $fields[(string) $field] = $value;
            }
            $images[(string) $inputId] = $fields;
        }

        $placements = [];
        foreach (is_array($data['placements'] ?? null) ? $data['placements'] : [] as $variantId => $slots) {
            foreach (is_array($slots) ? $slots : [] as $inputId => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $placement = [];
                foreach ($entry as $field => $value) {
                    if (is_numeric($value)) {
                        $placement[(string) $field] = (float) $value;
                    }
                }
                if ($placement !== []) {
                    $placements[(string) $variantId][(string) $inputId] = $placement;
                }
            }
        }

        return new self($texts, $hidden, $images, $placements);
    }

    /**
     * Canonical form: recursively key-sorted, empty top-level keys kept (the
     * shape is fixed), suitable for storage AND hashing.
     *
     * @return array{texts: array<string, string>, hidden: list<string>, images: array<string, array<string, mixed>>, placements: array<string, array<string, array<string, float>>>}
     */
    public function toArray(): array
    {
        $texts = $this->texts;
        ksort($texts);

        $hidden = array_values(array_unique($this->hidden));
        sort($hidden);

        $images = [];
        foreach ($this->images as $inputId => $entry) {
            ksort($entry);
            $images[$inputId] = $entry;
        }
        ksort($images);

        $placements = [];
        foreach ($this->placements as $variantId => $slots) {
            $sorted = [];
            foreach ($slots as $inputId => $entry) {
                ksort($entry);
                $sorted[$inputId] = $entry;
            }
            ksort($sorted);
            $placements[$variantId] = $sorted;
        }
        ksort($placements);

        return [
            'texts' => $texts,
            'hidden' => $hidden,
            'images' => $images,
            'placements' => $placements,
        ];
    }

    /**
     * Content identity of the fill — two exports with the same hash are the
     * same version.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
    }

    public function isEmpty(): bool
    {
        return $this->texts === [] && $this->hidden === [] && $this->images === [] && $this->placements === [];
    }

    /**
     * @param array<array-key, mixed> $rawHidden
     * @return list<string>
     */
    private static function hiddenIds(array $rawHidden): array
    {
        $hidden = [];
        foreach ($rawHidden as $inputId => $_) {
            $hidden[] = (string) $inputId;
        }

        return $hidden;
    }

    /**
     * Mirrors FillFormRequestParser::parseImageValues — shorthand string pick,
     * numeric transform fields, checkbox-ish hide — with floats rounded so an
     * identical re-export hashes identically.
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, array<string, mixed>>
     */
    private static function imageEntries(array $raw): array
    {
        $images = [];

        foreach ($raw as $inputId => $value) {
            $key = (string) $inputId;

            if (is_string($value)) {
                if ($value !== '') {
                    $images[$key] = ['imageId' => $value];
                }
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $entry = [];

            $imageId = $value['imageId'] ?? null;
            if (is_string($imageId) && $imageId !== '') {
                $entry['imageId'] = $imageId;
            }

            foreach (self::IMAGE_FLOAT_FIELDS as $field) {
                $candidate = $value[$field] ?? null;
                if (is_numeric($candidate)) {
                    $entry[$field] = round((float) $candidate, 4);
                }
            }

            if (in_array($value['hide'] ?? null, ['1', 'true', true, 1], true)) {
                $entry['hide'] = true;
            }

            if ($entry !== []) {
                $images[$key] = $entry;
            }
        }

        return $images;
    }
}
