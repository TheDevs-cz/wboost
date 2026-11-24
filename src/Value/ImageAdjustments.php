<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * "Styl vrstvy" › Úpravy obrázku — Photoshop-like adjustments over Fabric's
 * NATIVE `filters` array on an image. PHP twin of
 * `assets/controllers/canvas_image_filters.js`: same UI units, same
 * canonical order, same rounding (4 decimals), so a DSL-compiled image and
 * an editor-made one carry the identical array.
 *
 *   brightness  −100…100  → Brightness.brightness = v / 100
 *   contrast    −100…100  → Contrast.contrast     = v / 100
 *   saturation  −100…100  → Saturation.saturation = v / 100
 *   hue         −180…180° → HueRotation.rotation  = v / 180
 *   grayscale   bool      → Grayscale (luminosity)
 *   blur        0…100     → Blur.blur             = v / 400
 */
final readonly class ImageAdjustments
{
    /**
     * key => [Fabric type, Fabric prop, min, max, scale]
     *
     * @var array<string, array{string, string, int, int, int}>
     */
    public const array SPECS = [
        'brightness' => ['Brightness', 'brightness', -100, 100, 100],
        'contrast' => ['Contrast', 'contrast', -100, 100, 100],
        'saturation' => ['Saturation', 'saturation', -100, 100, 100],
        'hue' => ['HueRotation', 'rotation', -180, 180, 180],
        'blur' => ['Blur', 'blur', 0, 100, 400],
    ];

    public function __construct(
        public int $brightness = 0,
        public int $contrast = 0,
        public int $saturation = 0,
        public int $hue = 0,
        public bool $grayscale = false,
        public int $blur = 0,
    ) {
    }

    /**
     * @param array<array-key, mixed> $object a decoded canvas object
     */
    public static function fromObject(array $object): self
    {
        $values = ['brightness' => 0, 'contrast' => 0, 'saturation' => 0, 'hue' => 0, 'blur' => 0];
        $grayscale = false;

        foreach (self::knownFilters($object) as $filter) {
            if ($filter['type'] === 'Grayscale') {
                $grayscale = true;

                continue;
            }

            foreach (self::SPECS as $key => [$type, $prop, $min, $max, $scale]) {
                if ($filter['type'] === $type) {
                    $raw = $filter[$prop] ?? 0;
                    $values[$key] = max($min, min($max, (int) round((is_numeric($raw) ? (float) $raw : 0.0) * $scale)));
                }
            }
        }

        return new self($values['brightness'], $values['contrast'], $values['saturation'], $values['hue'], $grayscale, $values['blur']);
    }

    /**
     * The object's filters this class words, as plain arrays (anything else
     * is dropped — it would not survive a DSL round-trip either).
     *
     * @param array<array-key, mixed> $object
     * @return list<array<string, mixed>>
     */
    public static function knownFilters(array $object): array
    {
        $filters = $object['filters'] ?? null;
        if (!is_array($filters)) {
            return [];
        }

        $known = array_merge(array_column(self::SPECS, 0), ['Grayscale']);
        $result = [];

        foreach ($filters as $filter) {
            if (is_array($filter) && is_string($filter['type'] ?? null) && in_array($filter['type'], $known, true)) {
                /** @var array<string, mixed> $filter */
                $result[] = $filter;
            }
        }

        return $result;
    }

    public function isNeutral(): bool
    {
        return $this->toFabric() === [];
    }

    /**
     * Fabric's `filters` array, canonical order, neutral entries left out.
     *
     * @return list<array<string, mixed>>
     */
    public function toFabric(): array
    {
        $values = $this->values();
        $filters = [];

        foreach (['brightness', 'contrast', 'saturation', 'hue'] as $key) {
            [$type, $prop, , , $scale] = self::SPECS[$key];
            if ($values[$key] !== 0) {
                $filters[] = ['type' => $type, $prop => round($values[$key] / $scale, 4)];
            }
        }

        if ($this->grayscale) {
            $filters[] = ['type' => 'Grayscale', 'mode' => 'luminosity'];
        }

        if ($values['blur'] !== 0) {
            $filters[] = ['type' => 'Blur', 'blur' => round($values['blur'] / self::SPECS['blur'][4], 4)];
        }

        return $filters;
    }

    /**
     * The DSL `adjust` block — only the non-neutral keys.
     *
     * @return array<string, int|bool>
     */
    public function toDsl(): array
    {
        $data = array_filter($this->values(), static fn (int $value): bool => $value !== 0);

        if ($this->grayscale) {
            $data['grayscale'] = true;
        }

        return $data;
    }

    /**
     * @return array{brightness: int, contrast: int, saturation: int, hue: int, blur: int}
     */
    private function values(): array
    {
        return [
            'brightness' => $this->brightness,
            'contrast' => $this->contrast,
            'saturation' => $this->saturation,
            'hue' => $this->hue,
            'blur' => $this->blur,
        ];
    }
}
