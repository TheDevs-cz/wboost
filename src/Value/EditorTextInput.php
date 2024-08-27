<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class EditorTextInput
{
    public function __construct(
        public string $name,
        public null|int $maxLength,
        public bool $locked,
    ) {
    }

    /**
     * @return array{name: string, maxLength: null|int, locked: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'maxLength' => $this->maxLength,
            'locked' => $this->locked,
        ];
    }

    /**
     * @param array{name: string, maxLength: null|int, locked: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            maxLength: $data['maxLength'],
            locked: $data['locked'],
        );
    }

    /**
     * @return array<self>
     */
    public static function createCollectionFromJson(string $json): array
    {
        /** @var array<array{name: string, maxLength: null|int, locked: bool}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
