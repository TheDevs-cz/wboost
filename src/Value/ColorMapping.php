<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One colour variant's logo recolouring: `colors` maps a logo colour (the
 * SVG's literal, no `#`) to a target HEX; `opacities` holds the target's
 * "Krytí" in percent for the TRANSLUCENT ones only — an absent key = 100 %, so
 * rows written before opacity existed read unchanged.
 */
readonly final class ColorMapping
{
    /** @var array<string, int> */
    public array $opacities;

    /**
     * @param array<string, string> $colors
     * @param array<string, int> $opacities
     */
    public function __construct(
        public null|string $background,
        public array $colors,
        array $opacities = [],
    ) {
        $normalized = [];

        foreach ($opacities as $from => $opacity) {
            $opacity = max(0, min(100, $opacity));

            if ($opacity < 100) {
                $normalized[strtoupper((string) $from)] = $opacity;
            }
        }

        $this->opacities = $normalized;
    }

    /**
     * @param array{background: null|string, colors: array<string, string>, opacities?: array<string, int>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['background'], $data['colors'], $data['opacities'] ?? []);
    }

    /**
     * @return array{background: null|string, colors: array<string, string>, opacities: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'background' => $this->background,
            'colors' => $this->colors,
            'opacities' => $this->opacities,
        ];
    }

    /** Percent, 100 when the colour has no opacity set. */
    public function opacity(string $from): int
    {
        return $this->opacities[strtoupper($from)] ?? 100;
    }
}
