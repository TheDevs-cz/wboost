<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

use WBoost\Web\Value\ContainerRepeat;
use WBoost\Web\Value\ElementMoveAllowance;

/**
 * A container ("smart text area"): a vertical flow whose items reflow at
 * render time — a filled text that wraps to more lines pushes everything below
 * it down, hidden items collapse. Compiles to a
 * {@see \WBoost\Web\Value\CanvasContainer} entry inside the canvas JSON's
 * top-level `containers` key (plan §4.4 invariant 15).
 *
 * A container is a DEFINITION, not a drawable: it carries no placement and
 * takes no slot in the stack order — the compiler emits only text / image /
 * background elements into `canvas.objects[]`.
 *
 * {@see $memberIds} are text and DECORATIVE image slugs; {@see $childIds} are
 * other containers, each of which flows in this one as a single item.
 * Fillable image placeholders and the background layer are never members
 * (§4.4 invariant 18) — the parser refuses them by slug.
 *
 * ⚠️ **`maxHeight` is required for a ROOT container and optional for a NESTED
 * one, and that asymmetry is §4.4 speaking, not convenience.** Only the
 * outermost container's maxHeight gates overflow (the strict export 400 always
 * names the root); a nested container grows freely with its content, so
 * demanding a bound for it would be demanding a number that means nothing.
 * The compiler still has to emit a POSITIVE maxHeight for every container,
 * nested included — `CanvasContainer::fromArray()` drops a non-positive one
 * and `sanitizedContainers()` filters `maxHeight > 0` — so a null here is the
 * compiler's cue to synthesize an inert value, not to skip the key.
 *
 * ⚠️ Member ORDER in {@see $memberIds} is NOT the flow order. §4.4 invariant
 * 16 makes flow order the ascending designed `top` of the members, re-derived
 * by the compiler, never trusted from the document.
 */
readonly final class ContainerElement implements DesignElement
{
    public function __construct(
        public string $id,
        /**
         * Slugs of the text / decorative-image members.
         *
         * @var list<string>
         */
        public array $memberIds,
        /**
         * Slugs of the nested child containers.
         *
         * @var list<string>
         */
        public array $childIds,
        /**
         * Flow bound in px measured from the first item's designed top,
         * required on roots. Null = nested and unbounded (see the class note).
         */
        public null|float $maxHeight,
        /**
         * Uniform inter-item spacing in px, replacing the designed gaps of
         * THIS container. Null = keep the designed gaps.
         */
        public null|float $gap,
        /** Guaranteed clearance below the container in px. Null = 0. */
        public null|float $spaceAfter,
        /**
         * The WHOLE container may be hidden at fill time / through the API
         * (`inputs[<container id>] = {"hide": true}`) — it and everything in
         * its tree disappear and collapse.
         */
        public bool $hidable = false,
        /** Label of the hide toggle on the fill pages. Null = unnamed. */
        public null|string $name = null,
        /**
         * "Uživatel může posunout celý kontejner" (canvas `userMove` on the
         * definition): the end user may move the WHOLE block along the
         * allowed axes, up to the per-direction maxima (px, null = anywhere
         * on the artwork). Top-level containers only — inert (and linted)
         * on a nested one. Null = fixed in place.
         */
        public null|ElementMoveAllowance $userMove = null,
        /**
         * "Opakovatelný řádek" (canvas `repeat` on the definition): the end
         * user may show `min`…`max` copies of this container, `default` on a
         * fresh fill. NESTED containers only — the parent is the flow and its
         * root's `maxHeight` the bound — one per parent, never inside another
         * repeatable tree (all three are lint errors). Null = a plain
         * container.
         */
        public null|ContainerRepeat $repeat = null,
    ) {
    }

    public function kind(): ElementKind
    {
        return ElementKind::Container;
    }

    /**
     * Members + children — what §4.4's "≥ 2 members counting children" counts.
     *
     * @return list<string>
     */
    public function referencedIds(): array
    {
        return array_merge($this->memberIds, $this->childIds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'kind' => ElementKind::Container->value,
            'id' => $this->id,
            'members' => $this->memberIds,
            'children' => $this->childIds,
            'maxHeight' => $this->maxHeight,
            'gap' => $this->gap,
            'spaceAfter' => $this->spaceAfter,
            'hidable' => $this->hidable,
            'name' => $this->name,
        ];

        if ($this->userMove !== null) {
            $data['userMove'] = $this->userMove->toArray();
        }

        if ($this->repeat !== null) {
            $data['repeat'] = $this->repeat->toArray();
        }

        return $data;
    }
}
