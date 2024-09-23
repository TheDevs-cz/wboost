<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class ColorMapping
{
    public function __construct(
        public null|string $background,
        /** @var array<string, string> */
        public array $colors,
    ) {
    }

    /**
     * @param array{background: null|string, colors: array<string, string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['background'], $data['colors']);
    }

    /**
     * @return array{background: null|string, colors: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'background' => $this->background,
            'colors' => $this->colors,
        ];
    }
}
