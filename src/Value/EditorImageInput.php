<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * Designer-authored definition of a fillable IMAGE placeholder on a social
 * network template variant — the image counterpart of {@see EditorTextInput}.
 *
 * The designer drops an image on the canvas, marks it a placeholder and sets
 * per-slot limits; end-users / API consumers later drop their own picture into
 * the slot (centered, object-contain, clipped to the designer's frame) and may
 * move / resize / rotate it within the limits below. Bound to the Fabric image
 * object by the stable `inputId` UUID (mirrored as the object's `inputId`
 * custom property), never by position or name.
 */
readonly final class EditorImageInput
{
    /**
     * @param list<string> $allowedDirectoryIds Gallery {@see \WBoost\Web\Entity\FileDirectory}
     *        UUIDs (as strings) the user may pick fill images from for THIS slot.
     *        Empty = no folder is offered for this placeholder.
     */
    public function __construct(
        /**
         * Stable UUID v4 of the canvas image object this placeholder is bound to —
         * the same id carried as the `inputId` custom property on the Fabric object.
         */
        public string $inputId,
        public null|string $name,
        public null|string $description,
        public bool $allowMove,
        public bool $allowResize,
        public bool $allowRotate,
        public bool $hidable,
        public array $allowedDirectoryIds,
    ) {
    }

    /**
     * @return array{inputId: string, name: null|string, description: null|string, allowMove: bool, allowResize: bool, allowRotate: bool, hidable: bool, allowedDirectoryIds: list<string>}
     */
    public function toArray(): array
    {
        return [
            'inputId' => $this->inputId,
            'name' => $this->name,
            'description' => $this->description,
            'allowMove' => $this->allowMove,
            'allowResize' => $this->allowResize,
            'allowRotate' => $this->allowRotate,
            'hidable' => $this->hidable,
            'allowedDirectoryIds' => $this->allowedDirectoryIds,
        ];
    }

    /**
     * Accepts legacy / partial entries defensively (mirrors EditorTextInput): a
     * missing `inputId` mints a fresh UUID, and `allowedDirectoryIds` is
     * normalized to a list of non-empty strings so a malformed canvas payload
     * can never poison the allow-list check in ResolveImageOverrides.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $inputId = $data['inputId'] ?? null;

        if (!is_string($inputId) || $inputId === '') {
            $inputId = Uuid::uuid4()->toString();
            trigger_error(
                'EditorImageInput received entry without inputId; generating fresh UUID.',
                E_USER_WARNING,
            );
        }

        $name = $data['name'] ?? null;
        $description = $data['description'] ?? null;

        $allowedDirectoryIds = [];
        $rawAllowed = $data['allowedDirectoryIds'] ?? [];
        if (is_array($rawAllowed)) {
            foreach ($rawAllowed as $id) {
                if (is_string($id) && $id !== '') {
                    $allowedDirectoryIds[] = $id;
                }
            }
        }

        return new self(
            inputId: $inputId,
            name: is_string($name) ? $name : null,
            description: is_string($description) ? $description : null,
            allowMove: (bool) ($data['allowMove'] ?? false),
            allowResize: (bool) ($data['allowResize'] ?? false),
            allowRotate: (bool) ($data['allowRotate'] ?? false),
            hidable: (bool) ($data['hidable'] ?? false),
            allowedDirectoryIds: $allowedDirectoryIds,
        );
    }

    /**
     * @return array<self>
     */
    public static function createCollectionFromJson(string $json): array
    {
        /** @var array<array<string, mixed>> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
