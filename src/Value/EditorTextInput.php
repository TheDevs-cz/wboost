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
        /**
         * When true the end user fills this input through the simple WYSIWYG
         * (font face / color / underline as {@see RichTextRun}s) instead of a
         * plain text field, and the export accepts a `runs` value for it.
         */
        public bool $richText = false,
        /**
         * Lists inside the WYSIWYG (only meaningful with richText: true):
         * when enabled, the fill value's envelope may carry per-line types
         * (`lines: ["p","ul","ol",...]`) and the renderer lays the value out
         * as a block stack (paragraphs + bulleted/numbered items). The
         * `list*` fields are the admin's per-input styling; null = derived
         * default (see {@see ResolvedListStyle}).
         */
        public bool $lists = false,
        /** 'disc' | 'dash' | 'check' | 'image' (null = disc). */
        public null|string $listBullet = null,
        /** Gallery storage path of the custom bullet (bullet = 'image'). */
        public null|string $listBulletImage = null,
        /** Item text indent from the input's left edge, canvas px. */
        public null|float $listIndent = null,
        /** Extra vertical gap between list items, canvas px. */
        public null|float $listItemSpacing = null,
        /** Vertical gap between blocks (paragraph ↔ list), canvas px. */
        public null|float $listBlockSpacing = null,
        /**
         * Checkbox lists (on top of `lists`): when enabled the fill value may
         * carry 'cb' (unchecked) / 'cbx' (checked) line types and the user
         * checks/unchecks items in the WYSIWYG. Rendered as a rounded square
         * in the item's text color (checked adds a white check mark) unless
         * the admin picked custom gallery images per state below.
         */
        public bool $listCheckboxes = false,
        /** Gallery storage path of the custom UNCHECKED checkbox image. */
        public null|string $listCheckboxImage = null,
        /** Gallery storage path of the custom CHECKED checkbox image. */
        public null|string $listCheckboxCheckedImage = null,
        /**
         * "Vzorový text": the value rendered when the export/preview receives
         * NO override for this input — the admin-authored default fill.
         * Stored in the exact wire format a fill value uses: a plain string,
         * or the `{"runs":[...],"lines":[...]}` envelope (so it carries the
         * full rich feature set incl. lists). Always parsed LENIENTLY at
         * render time — a stale sample must never 400 an API consumer who
         * simply omitted the input.
         */
        public null|string $sampleValue = null,
    ) {
    }

    /**
     * @return array{inputId: string, name: null|string, maxLength: null|int, locked: bool, uppercase: bool, description: null|string, hidable: bool, richText: bool, lists: bool, listBullet: null|string, listBulletImage: null|string, listIndent: null|float, listItemSpacing: null|float, listBlockSpacing: null|float, listCheckboxes: bool, listCheckboxImage: null|string, listCheckboxCheckedImage: null|string, sampleValue: null|string}
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
            'richText' => $this->richText,
            'lists' => $this->lists,
            'listBullet' => $this->listBullet,
            'listBulletImage' => $this->listBulletImage,
            'listIndent' => $this->listIndent,
            'listItemSpacing' => $this->listItemSpacing,
            'listBlockSpacing' => $this->listBlockSpacing,
            'listCheckboxes' => $this->listCheckboxes,
            'listCheckboxImage' => $this->listCheckboxImage,
            'listCheckboxCheckedImage' => $this->listCheckboxCheckedImage,
            'sampleValue' => $this->sampleValue,
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
     * @param array{inputId?: string, name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool, richText?: bool, lists?: bool, listBullet?: null|string, listBulletImage?: null|string, listIndent?: null|float|int, listItemSpacing?: null|float|int, listBlockSpacing?: null|float|int, listCheckboxes?: bool, listCheckboxImage?: null|string, listCheckboxCheckedImage?: null|string, sampleValue?: null|string} $data
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

        $spacing = static function (mixed $value): null|float {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            $value = (float) $value;

            return is_finite($value) && $value >= 0 ? $value : null;
        };

        $bullet = $data['listBullet'] ?? null;
        if (!is_string($bullet) || !in_array($bullet, ['disc', 'dash', 'check', 'image'], true)) {
            $bullet = null;
        }

        $bulletImage = $data['listBulletImage'] ?? null;
        if (!is_string($bulletImage) || trim($bulletImage) === '') {
            $bulletImage = null;
        }

        $imagePath = static function (mixed $value): null|string {
            return is_string($value) && trim($value) !== '' ? $value : null;
        };

        return new self(
            inputId: $inputId,
            name: $data['name'],
            maxLength: $data['maxLength'],
            locked: $data['locked'],
            uppercase: $data['uppercase'] ?? false,
            description: $data['description'] ?? null,
            hidable: $data['hidable'] ?? false,
            richText: $data['richText'] ?? false,
            lists: ($data['lists'] ?? false) === true,
            listBullet: $bullet,
            listBulletImage: $bulletImage,
            listIndent: $spacing($data['listIndent'] ?? null),
            listItemSpacing: $spacing($data['listItemSpacing'] ?? null),
            listBlockSpacing: $spacing($data['listBlockSpacing'] ?? null),
            listCheckboxes: ($data['listCheckboxes'] ?? false) === true,
            listCheckboxImage: $imagePath($data['listCheckboxImage'] ?? null),
            listCheckboxCheckedImage: $imagePath($data['listCheckboxCheckedImage'] ?? null),
            sampleValue: self::sampleValueFrom($data['sampleValue'] ?? null),
        );
    }

    private static function sampleValueFrom(mixed $value): null|string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        // Defensive cap: the envelope of a MAX_TOTAL_LENGTH value with styles
        // stays far under this; anything bigger is garbage, not a sample.
        return mb_substr($value, 0, 60000);
    }

    /**
     * @return array<self>
     */
    public static function createCollectionFromJson(string $json): array
    {
        /** @var array<array{inputId?: string, name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool, richText?: bool, lists?: bool, listBullet?: null|string, listBulletImage?: null|string, listIndent?: null|float|int, listItemSpacing?: null|float|int, listBlockSpacing?: null|float|int, listCheckboxes?: bool, listCheckboxImage?: null|string, listCheckboxCheckedImage?: null|string, sampleValue?: null|string}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
