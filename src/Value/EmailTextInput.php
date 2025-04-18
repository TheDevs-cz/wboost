<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * @phpstan-type EmailTextInputArray array{id: string, content: string}
 */
readonly final class EmailTextInput
{
    public function __construct(
        public string $id,
        public string $content,
    ) {
    }

    /**
     * @return EmailTextInputArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
        ];
    }

    /**
     * @param EmailTextInputArray $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            content: $data['content'],
        );
    }

    /**
     * @return array<self>
     */
    public static function createCollectionFromJson(string $json): array
    {
        /** @var array<EmailTextInputArray> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
