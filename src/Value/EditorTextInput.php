<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class EditorTextInput
{
    public function __construct(
        public null|string $name,
        public null|int $maxLength,
        public bool $locked,
        public bool $uppercase,
        public null|string $description,
        public bool $hidable,
    ) {
    }

    /**
     * @return array{name: null|string, maxLength: null|int, locked: bool, uppercase: bool, description: null|string, hidable: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'maxLength' => $this->maxLength,
            'locked' => $this->locked,
            'uppercase' => $this->uppercase,
            'description' => $this->description,
            'hidable' => $this->hidable,
        ];
    }

    /**
     * @param array{name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            maxLength: $data['maxLength'],
            locked: $data['locked'],
            uppercase: $data['uppercase'] ?? false,
            description: $data['description'] ?? null,
            hidable: $data['hidable'] ?? false,
        );
    }

    /**
     * @return array<self>
     */
    public static function createCollectionFromJson(string $json): array
    {
        /** @var array<array{name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
