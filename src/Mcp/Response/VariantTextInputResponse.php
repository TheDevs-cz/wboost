<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One fillable TEXT placeholder of a variant.
 *
 * `id` is the only handle: it is a stable UUID, and two inputs of one variant
 * may legitimately share a `name` (the designer's label, often absent), so a
 * fill keyed by name is a bug waiting for the second "Title".
 *
 * The constraint fields are not advice. `maxLength` is enforced at export
 * (over-long values are rejected, not truncated), `uppercase` is applied by
 * the server so a value must not be pre-shouted, `locked` inputs cannot be
 * addressed at all, and `hidable` says whether the input may be hidden instead
 * of filled.
 *
 * The capability flags widen what a VALUE may be, and they nest: `lists` only
 * means anything with `richText`, `listCheckboxes` only with `lists`, and a
 * non-null `checklist` means the input IS one checkbox list — see
 * {@see VariantChecklistResponse}.
 *
 * `sampleValue` is the designer's default fill in the exact wire format a
 * value uses (a plain string, or the `{"runs":…,"lines":…}` envelope for rich
 * ones). It is what renders when the input is OMITTED from a fill — sending an
 * empty string suppresses it, which is not the same thing.
 *
 * `containerId` links the input to a container in the variant's `containers[]`:
 * its text reflows together with that container's other members and shares the
 * container's height budget.
 *
 * `fontOptions` are the exact face strings THIS input may be filled in —
 * designed font first. Non-null means the user may switch: send the pick as
 * the value's `fontFamily` (`{ value, fontFamily }`, a whole-text switch), and
 * for a rich input it is also the whitelist a run's `fontFamily` must respect.
 * Null = no choice, the designed font renders.
 *
 * Deliberately NOT reported: the resolved list styling (bullet glyph, indent,
 * spacing) and the designed text metrics. Both exist in the REST listing for
 * consumers that re-draw the layout themselves; here the server render is the
 * authority, and an agent that cannot draw bullets has no use for their px
 * offsets.
 */
readonly final class VariantTextInputResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|string $description,
        public null|int $maxLength,
        public bool $uppercase,
        public bool $locked,
        public bool $hidable,
        public bool $richText,
        public bool $lists,
        public bool $listCheckboxes,
        public null|VariantChecklistResponse $checklist,
        public null|string $sampleValue,
        public null|VariantInputFrameResponse $frame,
        public null|string $containerId,
        /** @var null|list<string> */
        public null|array $fontOptions = null,
        /**
         * Rich inputs: the colour allowlist for the runs — null = any hex, an
         * EMPTY list = the colour cannot be changed, a list = only these
         * `#rrggbb` swatches; outside it a run's colour is refused
         * (`color_not_allowed`).
         *
         * @var null|list<string>
         */
        public null|array $colorOptions = null,
    ) {
    }
}
