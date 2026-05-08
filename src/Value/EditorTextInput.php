<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Ramsey\Uuid\Uuid;

readonly final class EditorTextInput
{
    public function __construct(
        /**
         * Stable UUID v4 identifier of the canvas object this input is bound
         * to. Replaces the legacy positional / name-based binding so two
         * inputs may legitimately share a `name`.
         */
        public string $inputId,
        public null|string $name,
        public null|int $maxLength,
        public bool $locked,
        public bool $uppercase,
        public null|string $description,
        public bool $hidable,
    ) {
    }

    /**
     * @return array{inputId: string, name: null|string, maxLength: null|int, locked: bool, uppercase: bool, description: null|string, hidable: bool}
     */
    public function toArray(): array
    {
        return [
            'inputId' => $this->inputId,
            'name' => $this->name,
            'maxLength' => $this->maxLength,
            'locked' => $this->locked,
            'uppercase' => $this->uppercase,
            'description' => $this->description,
            'hidable' => $this->hidable,
        ];
    }

    /**
     * Accepts legacy entries without `inputId` (defensive — there should be
     * no such rows in the DB after the Stage 2 migration runs, but the JS
     * editor may briefly hand us pre-migration data and the API must not
     * blow up). When `inputId` is missing a fresh UUID v4 is minted; the
     * caller is responsible for stamping the matching id onto the canvas
     * object on the next save.
     *
     * @param array{inputId?: string, name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        $inputId = $data['inputId'] ?? null;

        if (!is_string($inputId) || $inputId === '') {
            $inputId = Uuid::uuid4()->toString();
            trigger_error(
                'EditorTextInput received entry without inputId; generating fresh UUID. Run app:social-template:migrate-input-ids.',
                E_USER_WARNING,
            );
        }

        return new self(
            inputId: $inputId,
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
        /** @var array<array{inputId?: string, name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
