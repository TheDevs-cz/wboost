<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class SvgImage implements \Stringable
{
    public function __construct(
        public string $filePath,
        /** @var array<string> */
        public array $detectedColors,
    ) {
    }

    /**
     * @param array{filePath: string, detectedColors: array<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['filePath'], $data['detectedColors']);
    }

    /**
     * @return array{filePath: string, detectedColors: array<string>}
     */
    public function toArray(): array
    {
        return [
            'filePath' => $this->filePath,
            'detectedColors' => $this->detectedColors,
        ];
    }

    public function __toString(): string
    {
        return $this->filePath;
    }
}
