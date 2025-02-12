<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class MailTextInput
{
    public function __construct(
        public string $name,
        public null|int $maxLength,
        public bool $uppercase,
        public null|string $description
    ) {
    }

    /**
     * @return array{name: string, maxLength: null|int, uppercase: bool, description: null|string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'maxLength' => $this->maxLength,
            'uppercase' => $this->uppercase,
            'description' => $this->description,
        ];
    }

    /**
     * @param array{name: string, maxLength: null|int, uppercase?: bool, description?: null|string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            maxLength: $data['maxLength'],
            uppercase: $data['uppercase'] ?? false,
            description: $data['description'] ?? null,
        );
    }

    /**
     * @return array<self>
     */
    public static function createCollectionFromJson(string $json): array
    {
        /** @var array<array{name: string, maxLength: null|int, uppercase?: bool, description?: null|string}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
