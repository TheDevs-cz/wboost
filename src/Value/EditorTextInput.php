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
         * DEDICATED checklist component ("Přidat zaškrtávací seznam"): the
         * whole input IS one checkbox list — the fill page renders a simple
         * per-item editor (rows with a checkbox) instead of the WYSIWYG, and
         * the four capability flags below control what the end user may do.
         * Always created with richText + lists + listCheckboxes forced true
         * (the value model and render pipeline are the plain checkbox-list
         * ones; only the editing surface differs).
         */
        public bool $checklist = false,
        /** Checklist capability: the user may ADD new items. */
        public bool $checklistAdd = true,
        /** Checklist capability: the user may REMOVE items. */
        public bool $checklistRemove = true,
        /** Checklist capability: the user may EDIT item texts. */
        public bool $checklistEditText = true,
        /** Checklist capability: the user may CHECK/UNCHECK items. */
        public bool $checklistToggle = true,
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
        /**
         * Font choice ("Uživatel může přepínat písmo"): the ADDITIONAL font
         * faces — exact `"<Font> (<Face>)"` family strings, see
         * {@see \WBoost\Web\Entity\Font::faceFamily()} — the end user may
         * switch this input to, on top of the designed one. Resolved per
         * input by {@see \WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions};
         * a face renamed / deleted after the admin picked it simply drops
         * out of the offer. See {@see $fontChoice} for what an EMPTY list
         * means.
         *
         * @var list<string>
         */
        public array $allowedFonts = [],
        /**
         * Whether the admin CONFIGURED the face offer (the popover toggle).
         * A plain input offers a switch only with picks in `allowedFonts`,
         * so the flag alone changes nothing there. A RICH input is where it
         * matters: unconfigured, its WYSIWYG offers every face of the
         * designed font (bold / italic keep working — the pre-choice
         * behaviour); configured, it offers the designed face PLUS the
         * picks and nothing else — "if only one face is allowed, that one
         * is always used". Stored separately from the list because an
         * empty list is a legitimate configuration (designed face only)
         * and rows saved before the flag existed carry `[]` too.
         */
        public bool $fontChoice = false,
        /**
         * Colour allowlist for the WYSIWYG (rich inputs): null = any colour
         * (brand swatches + a free picker, the pre-allowlist behaviour), an
         * EMPTY list = the colour cannot be changed at all, a list = only
         * these swatches (lowercase `#rrggbb`). Enforced at render time on
         * every run's colour ({@see RichText::fromRaw()}): strict 400
         * `color_not_allowed`, lenient strip.
         *
         * @var null|list<string>
         */
        public null|array $allowedColors = null,
    ) {
    }

    /** Whether the end user of a PLAIN input can switch its font at all. */
    public function offersFontChoice(): bool
    {
        return $this->allowedFonts !== [];
    }

    /**
     * Whether the face offer of a RICH input is admin-configured (designed
     * face + picks only) rather than the whole designed family.
     */
    public function restrictsFaces(): bool
    {
        return $this->fontChoice || $this->allowedFonts !== [];
    }

    /**
     * @return array{inputId: string, name: null|string, maxLength: null|int, locked: bool, uppercase: bool, description: null|string, hidable: bool, richText: bool, lists: bool, listBullet: null|string, listBulletImage: null|string, listIndent: null|float, listItemSpacing: null|float, listBlockSpacing: null|float, listCheckboxes: bool, listCheckboxImage: null|string, listCheckboxCheckedImage: null|string, checklist: bool, checklistAdd: bool, checklistRemove: bool, checklistEditText: bool, checklistToggle: bool, sampleValue: null|string, allowedFonts: list<string>, fontChoice: bool, allowedColors: null|list<string>}
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
            'checklist' => $this->checklist,
            'checklistAdd' => $this->checklistAdd,
            'checklistRemove' => $this->checklistRemove,
            'checklistEditText' => $this->checklistEditText,
            'checklistToggle' => $this->checklistToggle,
            'sampleValue' => $this->sampleValue,
            'allowedFonts' => $this->allowedFonts,
            'fontChoice' => $this->fontChoice,
            'allowedColors' => $this->allowedColors,
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
     * @param array{inputId?: string, name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool, richText?: bool, lists?: bool, listBullet?: null|string, listBulletImage?: null|string, listIndent?: null|float|int, listItemSpacing?: null|float|int, listBlockSpacing?: null|float|int, listCheckboxes?: bool, listCheckboxImage?: null|string, listCheckboxCheckedImage?: null|string, checklist?: bool, checklistAdd?: bool, checklistRemove?: bool, checklistEditText?: bool, checklistToggle?: bool, sampleValue?: null|string, allowedFonts?: mixed, fontChoice?: mixed, allowedColors?: mixed} $data
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
            checklist: ($data['checklist'] ?? false) === true,
            checklistAdd: ($data['checklistAdd'] ?? true) === true,
            checklistRemove: ($data['checklistRemove'] ?? true) === true,
            checklistEditText: ($data['checklistEditText'] ?? true) === true,
            checklistToggle: ($data['checklistToggle'] ?? true) === true,
            sampleValue: self::sampleValueFrom($data['sampleValue'] ?? null),
            allowedFonts: self::allowedFontsFrom($data['allowedFonts'] ?? null),
            fontChoice: ($data['fontChoice'] ?? false) === true,
            allowedColors: self::allowedColorsFrom($data['allowedColors'] ?? null),
        );
    }

    /**
     * Defensive read of the colour allowlist: null stays null (any colour),
     * a list keeps its well-formed hex entries normalized and deduped — an
     * all-garbage list becomes an EMPTY list, i.e. colour locked, which is
     * the safe reading of "the admin restricted colours but named none".
     *
     * @return null|list<string>
     */
    private static function allowedColorsFrom(mixed $value): null|array
    {
        if ($value === null || !is_array($value)) {
            return null;
        }

        $colors = [];

        foreach ($value as $color) {
            if (!is_string($color)) {
                continue;
            }

            $normalized = RichText::normalizeHexColor($color);

            if ($normalized !== null && !in_array($normalized, $colors, true)) {
                $colors[] = $normalized;
            }
        }

        return $colors;
    }

    /**
     * Defensive read of the admin's font pick: non-empty strings only,
     * deduped, order kept (the resolver re-orders by project font anyway).
     *
     * @return list<string>
     */
    private static function allowedFontsFrom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $families = [];

        foreach ($value as $family) {
            if (!is_string($family) || trim($family) === '' || in_array($family, $families, true)) {
                continue;
            }

            $families[] = $family;
        }

        return $families;
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
        /** @var array<array{inputId?: string, name: null|string, maxLength: null|int, locked: bool, uppercase?: bool, description?: null|string, hidable?: bool, richText?: bool, lists?: bool, listBullet?: null|string, listBulletImage?: null|string, listIndent?: null|float|int, listItemSpacing?: null|float|int, listBlockSpacing?: null|float|int, listCheckboxes?: bool, listCheckboxImage?: null|string, listCheckboxCheckedImage?: null|string, checklist?: bool, checklistAdd?: bool, checklistRemove?: bool, checklistEditText?: bool, checklistToggle?: bool, sampleValue?: null|string, allowedFonts?: mixed, fontChoice?: mixed, allowedColors?: mixed}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $collection = [];

        foreach ($data as $inputData) {
            $collection[] = self::fromArray($inputData);
        }

        return $collection;
    }
}
