<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * Repeatable container rows (container-rows plan P2) — the server-side row
 * model. A NESTED container marked `repeat: {max, min, default}` is ONE
 * designed row; every fill / render / resolve path works on a clone of the
 * variant EXPANDED to `max` materialised rows, and the rows a fill does not
 * use are hidden through the existing hide mechanism (which collapses them —
 * the P0 invariant "hidden row ≡ absent row"). Hence no change to the shared
 * layout engine, the render template or the echo painter: the canvas is the
 * same for every row count, only the hides differ.
 *
 * **Expansion** ({@see expandCanvas()}), pure + idempotent:
 *
 * 1. Stamp every textbox's inputId from the positional binding (the
 *    binder's contract — clones must carry real ids before they move).
 * 2. For each repeatable row R (slot 0 keeps its ORIGINAL ids): deep-copy R's
 *    member objects (texts, decorative images, image slots, shapes — deep
 *    members of nested containers too) for slots k = 1…max−1 with derived
 *    ids ({@see slotId()}), translated down by `k × pitch`, inserted in
 *    `objects[]` right after R's last member (z-order local to the row).
 *    `pitch` = R's designed extent height + (parent `gap` ?? the designed
 *    gap from R to the next item in the parent ?? 0).
 * 3. Every item BELOW R in its parent — and, walking up, every item below
 *    the branch holding R in each ancestor — moves down by `(max−1) × pitch`,
 *    so the designed flow order and the designed gaps after the rows stay
 *    exactly what the designer drew (a footer keeps its gap to the LAST
 *    active row: the engine measures the gap before an item against its
 *    designed predecessor, hidden or not).
 * 4. Slot container definitions are cloned with derived ids
 *    (`repeatSlotOf: R`), inserted in the parent's `memberContainerIds`
 *    right after R, and R gets `repeatSlots` = all slot ids (slot 0 first) —
 *    the idempotency marker and the hide expansion's lookup table.
 * 5. `inputs[]` is REBUILT in textbox object order (the i-th visible textbox
 *    ↔ `inputs[i]` invariant holds by construction); cloned text / image
 *    inputs carry the template row's settings — including its sample text,
 *    which is what a new row starts from (Jan, 2026-11-09).
 *
 * **Never persist the result.** {@see expand()} follows the documents'
 * clone-first rule ({@see \WBoost\Web\Services\TemplateDocument\DocumentPageView}):
 * the managed entity is never touched, the clone is not in the identity map.
 * Pass the ORIGINAL entity to anything that writes (export versions, usage
 * records, messages) — only resolvers, placeholder builders and renderers
 * take the expanded clone. Design-time readers (editor, API listing, MCP
 * describe/decompile, group projector, font usage) never expand.
 *
 * A variant without a (valid) repeatable row is returned AS IS — same
 * instance, same bytes — so every existing design renders byte-identically.
 */
final readonly class RepeatableRows
{
    /**
     * Fixed namespace of the derived slot ids (uuid5). Never change it: the
     * derived ids are fill-state keys (text / hide / font / colour maps,
     * stored export versions, document fill keys).
     */
    public const string SLOT_ID_NAMESPACE = '6f1d9a52-3c8e-5b47-9a1e-0c2f4d8b7e31';

    /**
     * The id of `$originalId` (a member inputId or a container id of the row
     * tree) in slot `$slot` of row `$rowId`. Slot 0 is the designed row and
     * keeps the original id; slot k ≥ 1 gets a deterministic uuid5 —
     * computed only here (the markup carries the ids, so there is no JS twin).
     *
     * A row MERGED from hand-numbered copies (P6) overrides this per slot
     * with the copies' old ids (`repeat.slots`, applied inside
     * {@see expandCanvas()} — read the expanded design, not this function,
     * to learn a merged row's real ids).
     */
    public static function slotId(string $rowId, string $originalId, int $slot): string
    {
        if ($slot === 0) {
            return $originalId;
        }

        return Uuid::uuid5(self::SLOT_ID_NAMESPACE, $rowId . '#' . $slot . '#' . $originalId)->toString();
    }

    /**
     * The variant as every fill / render path must see it. Returns the SAME
     * instance when there is nothing to expand (no repeatable row, or
     * already expanded).
     */
    public static function expand(TemplateVariant $variant): TemplateVariant
    {
        // Read everything off the original first: on a lazy proxy this also
        // initialises it, so the clone carries real state.
        $canvasJson = $variant->canvas;

        if (!str_contains($canvasJson, '"repeat"')) {
            return $variant; // cheap pre-check — the common case decodes nothing
        }

        $decoded = json_decode($canvasJson, true);
        if (!is_array($decoded)) {
            return $variant;
        }

        $inputs = array_values($variant->inputs);
        $imageInputs = array_values($variant->imageInputs);
        $previewImagePath = $variant->previewImagePath;

        $expanded = self::expandCanvas($decoded, $inputs, $imageInputs);
        if ($expanded === null) {
            return $variant;
        }

        $encoded = json_encode($expanded['canvas'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return $variant;
        }

        $clone = clone $variant;
        $clone->editCanvas($encoded, $expanded['inputs'], $previewImagePath, $expanded['imageInputs']);

        return $clone;
    }

    /**
     * Whether the variant's canvas carries an (unexpanded or expanded)
     * repeatable row — the gate of every row-specific UI / state.
     */
    public static function hasRepeatableRows(TemplateVariant $variant): bool
    {
        return self::repeatableContainers($variant) !== [];
    }

    /**
     * @return list<CanvasContainer>
     */
    public static function repeatableContainers(TemplateVariant $variant): array
    {
        if (!str_contains($variant->canvas, '"repeat"')) {
            return [];
        }

        $decoded = json_decode($variant->canvas, true);

        return array_values(array_filter(
            CanvasContainer::collectionFromCanvas(is_array($decoded) ? $decoded : []),
            static fn (CanvasContainer $container): bool => $container->repeat !== null,
        ));
    }

    /**
     * Row container id → the designer's default count, for every repeatable
     * row of the given variants (a group's dimensions share ids — first wins).
     *
     * @param iterable<TemplateVariant> $variants
     * @return array<string, int>
     */
    public static function defaultCounts(iterable $variants): array
    {
        $counts = [];
        foreach ($variants as $variant) {
            foreach (self::repeatableContainers($variant) as $container) {
                \assert($container->repeat !== null);
                $counts[$container->id] ??= $container->repeat->default;
            }
        }

        return $counts;
    }

    /**
     * Raw posted / stored counts → row container id → the clamped count, for
     * the repeatable rows of the given variants only (unknown ids and
     * non-numeric values dropped; a missing row gets its default). The one
     * normalisation every surface (Live prop, form POST, version seed) uses.
     *
     * @param iterable<TemplateVariant> $variants
     * @param array<array-key, mixed> $raw
     * @return array<string, int>
     */
    public static function normalizeCounts(iterable $variants, array $raw): array
    {
        $counts = [];
        foreach ($variants as $variant) {
            foreach (self::repeatableContainers($variant) as $container) {
                \assert($container->repeat !== null);
                if (isset($counts[$container->id])) {
                    continue;
                }
                $value = $raw[$container->id] ?? null;
                $requested = is_int($value) ? $value : (is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null);
                $counts[$container->id] = $container->repeat->clamp($requested);
            }
        }

        return $counts;
    }

    /**
     * Row counts IMPLIED by the ids a fill addresses, for fills that carry no
     * count of their own: an export version recorded before the row existed
     * (the P6 merge keeps the hand-numbered rows' ids alive as slot ids, so
     * a 25-version-old "Střelec 3" value addresses slot 2), and an API / MCP
     * body (no `rows` field yet). A row whose slot k ≥ 1 is addressed — any
     * deep member id or the slot container id itself (a container hide) —
     * shows at least k + 1 rows: `max(default, k + 1)` clamped to
     * `[min, max]`. Rows with nothing addressed beyond slot 0 are LEFT OUT
     * (callers fall back to the default), so an explicit count always wins:
     * `$explicit + inferredCounts(…)`.
     *
     * NEVER feed it a web fill page's values: those carry every materialised
     * slot's mirror (sample texts included) and always carry the count.
     *
     * @param iterable<TemplateVariant> $variants EXPANDED variants (a group's dimensions share ids — first wins)
     * @param list<array-key> $addressedIds
     * @return array<string, int>
     */
    public static function inferredCounts(iterable $variants, array $addressedIds): array
    {
        if ($addressedIds === []) {
            return [];
        }

        $counts = [];

        foreach ($variants as $variant) {
            if (!str_contains($variant->canvas, '"repeatSlots"')) {
                continue; // no expanded row — nothing can be addressed
            }
            $decoded = json_decode($variant->canvas, true);
            // A group's dimensions share row ids (and the addressed ids): the
            // first dimension that infers a count for a row keeps it.
            $counts += self::inferredCountsFor(CanvasContainer::collectionFromCanvas(is_array($decoded) ? $decoded : []), $addressedIds);
        }

        return $counts;
    }

    /**
     * {@see inferredCounts()} over one EXPANDED container collection.
     *
     * @param list<CanvasContainer> $containers
     * @param list<array-key> $addressedIds
     * @return array<string, int>
     */
    public static function inferredCountsFor(array $containers, array $addressedIds): array
    {
        $addressed = array_fill_keys(array_map(strval(...), $addressedIds), true);
        $counts = [];

        foreach ($containers as $row) {
            if ($row->repeat === null || $row->repeatSlotIds === []) {
                continue;
            }

            for ($slot = count($row->repeatSlotIds) - 1; $slot >= 1; $slot--) {
                $slotContainer = CanvasContainer::findById($containers, $row->repeatSlotIds[$slot]);
                if ($slotContainer === null) {
                    continue;
                }
                $ids = [$slotContainer->id, ...$slotContainer->deepMemberInputIds($containers)];
                if (array_filter($ids, static fn (string $id): bool => isset($addressed[$id])) !== []) {
                    $counts[$row->id] = $row->repeat->clamp(max($row->repeat->default, $slot + 1));
                    break;
                }
            }
        }

        return $counts;
    }

    /**
     * The hide expansion of the row model — the ONE place an unused row
     * turns into hidden objects: the deep member ids of every materialised
     * slot k ≥ count of every repeatable row (count = `$rowCounts[rowId]`
     * clamped to `[min, max]`, the default when absent). Rows of an
     * UNexpanded collection have no slots and contribute nothing.
     *
     * @param list<CanvasContainer> $containers the rendered (expanded) variant's
     * @param array<string, int> $rowCounts row container id → requested count
     * @return list<string>
     */
    public static function inactiveMemberIds(array $containers, array $rowCounts): array
    {
        return CanvasContainer::inactiveRowMemberIds($containers, $rowCounts);
    }

    /**
     * How many rows of `$rowId` fit on this variant by DESIGNED geometry
     * (every text at its designed height; the other rows at
     * `$otherCounts` / their defaults): the largest count in `[min, max]`
     * whose root flow neither exceeds its `maxHeight` nor runs past the
     * page bottom ({@see DesignedContainerFlow}). `min` when even that does
     * not fit — a fill can never go below it. Null for an unknown row.
     *
     * This is the server's ESTIMATE (the editor's "fits N" prefill, the add
     * gate's upper bound); the fill page's own gate measures the real
     * wrapped heights.
     *
     * @param array<string, int> $otherCounts
     */
    public static function designedCapacity(TemplateVariant $variant, string $rowId, array $otherCounts = []): null|int
    {
        $variant = self::expand($variant);
        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $containers = CanvasContainer::collectionFromCanvas($canvas);
        $row = CanvasContainer::findById($containers, $rowId);

        if ($row === null || $row->repeat === null) {
            return null;
        }

        $root = $row;
        while (($parent = self::parentOf($containers, $root->id)) !== null && $parent->id !== $row->id) {
            $root = $parent;
        }

        for ($count = $row->repeat->max; $count > $row->repeat->min; $count--) {
            $hidden = array_fill_keys(CanvasContainer::inactiveRowMemberIds($containers, [$rowId => $count] + $otherCounts), true);
            $overflow = DesignedContainerFlow::overflowByRoot($canvas, $hidden, (float) $variant->dimension->height())[$root->id] ?? 0.0;
            if ($overflow <= self::FIT_TOLERANCE_PX) {
                return $count;
            }
        }

        return $row->repeat->min;
    }

    /**
     * Whether one more row of `$rowId` may be added to a fill showing
     * `$count` rows — for a GROUP the count is shared and the SMALLEST
     * dimension decides (Jan, 2026-11-09): below `max` and within the
     * designed capacity of every member that carries the row.
     *
     * @param iterable<TemplateVariant> $variants
     * @param array<string, int> $otherCounts
     */
    public static function canAddRow(iterable $variants, string $rowId, int $count, array $otherCounts = []): bool
    {
        $known = false;
        foreach ($variants as $variant) {
            $capacity = self::designedCapacity($variant, $rowId, $otherCounts);
            if ($capacity === null) {
                continue;
            }
            $known = true;
            if ($count + 1 > $capacity) {
                return false;
            }
        }

        return $known;
    }

    /** Float noise of designed geometry must not flip a fit. */
    private const float FIT_TOLERANCE_PX = 0.5;

    /**
     * Row container id → per slot (slot 0 first) the deep member ids of that
     * slot — what a client mirror needs to hide unused rows the way the
     * render does (overlay reflow), without re-deriving the ids in JS.
     *
     * @param list<CanvasContainer> $containers an EXPANDED collection
     * @return array<string, array{min: int, max: int, default: int, slots: list<list<string>>}>
     */
    public static function slotMemberMap(array $containers): array
    {
        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] ??= $container;
        }

        $map = [];
        foreach ($containers as $container) {
            if ($container->repeat === null || $container->repeatSlotIds === []) {
                continue;
            }

            $slots = [];
            foreach ($container->repeatSlotIds as $slotContainerId) {
                $slot = $byId[$slotContainerId] ?? null;
                $slots[] = $slot === null ? [] : $slot->deepMemberInputIds($containers);
            }

            $map[$container->id] = $container->repeat->toArray() + ['slots' => $slots];
        }

        return $map;
    }

    /**
     * The pure expansion (see the class docblock). Null when there is nothing
     * to expand: no valid repeatable row, or the canvas is already expanded.
     *
     * @param array<array-key, mixed> $canvas decoded canvas JSON
     * @param list<EditorTextInput> $inputs
     * @param list<EditorImageInput> $imageInputs
     * @return null|array{canvas: array<array-key, mixed>, inputs: list<EditorTextInput>, imageInputs: list<EditorImageInput>}
     */
    public static function expandCanvas(array $canvas, array $inputs, array $imageInputs): null|array
    {
        if (!is_array($canvas['objects'] ?? null) || !is_array($canvas['containers'] ?? null)) {
            return null;
        }

        $collection = CanvasContainer::collectionFromCanvas($canvas);
        $rows = [];
        foreach ($collection as $container) {
            if ($container->repeat === null) {
                continue;
            }
            if ($container->repeatSlotIds !== []) {
                return null; // already expanded — idempotent
            }
            $rows[] = $container;
        }

        if ($rows === []) {
            return null;
        }

        // 1. Stamp the positional binding onto the textboxes.
        /** @var list<mixed> $objects */
        $objects = array_values($canvas['objects']);
        foreach (TextInputObjectBinder::positionalBinding(['objects' => $objects], $inputs) as $index => $inputId) {
            if (is_array($objects[$index] ?? null)) {
                $objects[$index]['inputId'] = $inputId;
            }
        }

        /** @var list<mixed> $rawContainers */
        $rawContainers = array_values($canvas['containers']);

        $textInputsById = [];
        foreach ($inputs as $input) {
            $textInputsById[$input->inputId] ??= $input;
        }
        $imageInputsById = [];
        foreach ($imageInputs as $input) {
            $imageInputsById[$input->inputId] ??= $input;
        }

        $clonedImageInputs = [];

        // Legacy slot ids (P6 merge) may only be used while nothing in the
        // design carries them already, and only once across all rows — a
        // collision falls back to the derived id (never two objects, two
        // inputs or two containers with one id).
        $taken = self::idsInDesign($objects, $rawContainers);
        $claimedLegacy = [];

        foreach ($rows as $row) {
            \assert($row->repeat !== null);
            $parent = self::parentOf($collection, $row->id);
            if ($parent === null) {
                continue; // cannot happen — collectionFromCanvas keeps nested rows only
            }

            $treeContainerIds = self::treeContainerIds($collection, $row);
            $memberIds = array_flip($row->deepMemberInputIds($collection));
            $idMaps = self::legacyIdMaps($row, $memberIds + $treeContainerIds, $taken, $claimedLegacy);

            $memberIndexes = [];
            foreach ($objects as $index => $object) {
                if (is_array($object) && is_string($object['inputId'] ?? null) && isset($memberIds[$object['inputId']])) {
                    $memberIndexes[] = $index;
                }
            }

            $extent = self::extentOfIds($objects, $memberIds);
            if ($memberIndexes === [] || $extent === null) {
                continue; // nothing visible to repeat
            }

            $gap = $parent->gap ?? self::designedGapAfter($objects, $collection, $parent, $row, $extent) ?? 0.0;
            $pitch = max(1.0, ($extent[1] - $extent[0]) + $gap);
            $slots = $row->repeat->max;
            $delta = ($slots - 1) * $pitch;

            // 3. Everything below the row (in its parent, then up the
            //    ancestors) moves down by the materialised rows' height.
            if ($delta > 0.0) {
                $shift = self::idsBelowBranch($objects, $collection, $row);
                foreach ($objects as $index => $object) {
                    if (is_array($object) && is_string($object['inputId'] ?? null) && isset($shift[$object['inputId']])) {
                        $objects[$index] = self::translated($object, $delta);
                    }
                }
            }

            // 2. Clone the row's objects per slot.
            $clones = [];
            for ($slot = 1; $slot < $slots; $slot++) {
                foreach ($memberIndexes as $index) {
                    $object = $objects[$index];
                    \assert(is_array($object));
                    $clones[] = self::translated(self::rekeyed($object, $row->id, $slot, $idMaps[$slot] ?? []), $slot * $pitch);
                }
            }
            $insertAt = max($memberIndexes) + 1;
            array_splice($objects, $insertAt, 0, $clones);

            // 4. Slot container definitions.
            $slotIds = [$row->id];
            $newDefinitions = [];
            for ($slot = 1; $slot < $slots; $slot++) {
                $slotIds[] = $idMaps[$slot][$row->id] ?? self::slotId($row->id, $row->id, $slot);
                foreach ($rawContainers as $raw) {
                    if (!is_array($raw) || !is_string($raw['id'] ?? null) || !isset($treeContainerIds[$raw['id']])) {
                        continue;
                    }
                    $newDefinitions[] = self::slotDefinition($raw, $row->id, $slot, $idMaps[$slot] ?? []);
                }
            }

            foreach ($rawContainers as $i => $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                if (($raw['id'] ?? null) === $row->id) {
                    $raw['repeatSlots'] = $slotIds;
                    $rawContainers[$i] = $raw;
                } elseif (($raw['id'] ?? null) === $parent->id && is_array($raw['memberContainerIds'] ?? null)) {
                    $children = [];
                    foreach ($raw['memberContainerIds'] as $childId) {
                        $children[] = $childId;
                        if ($childId === $row->id) {
                            array_push($children, ...array_slice($slotIds, 1));
                        }
                    }
                    $raw['memberContainerIds'] = $children;
                    $rawContainers[$i] = $raw;
                }
            }
            array_push($rawContainers, ...$newDefinitions);

            // 5a. Cloned input definitions (texts are re-ordered below).
            foreach (array_keys($memberIds) as $memberId) {
                $memberId = (string) $memberId;
                for ($slot = 1; $slot < $slots; $slot++) {
                    $derived = $idMaps[$slot][$memberId] ?? self::slotId($row->id, $memberId, $slot);
                    if (isset($textInputsById[$memberId])) {
                        $textInputsById[$derived] = EditorTextInput::fromArray(['inputId' => $derived] + $textInputsById[$memberId]->toArray());
                    }
                    if (isset($imageInputsById[$memberId])) {
                        $clonedImageInputs[$memberId][] = EditorImageInput::fromArray(['inputId' => $derived] + $imageInputsById[$memberId]->toArray());
                    }
                }
            }

            // The next row's "below" walk must see this row's slots.
            $collection = CanvasContainer::collectionFromCanvas(['containers' => $rawContainers]);
        }

        // 5b. Rebuild inputs[] in textbox order — exactly the binder's walk.
        $rebuilt = [];
        $used = [];
        foreach ($objects as $object) {
            if (!is_array($object) || !is_string($object['type'] ?? null) || strtolower($object['type']) !== 'textbox') {
                continue;
            }
            if (($object['visible'] ?? true) === false) {
                continue;
            }
            $inputId = $object['inputId'] ?? null;
            if (!is_string($inputId) || !isset($textInputsById[$inputId]) || isset($used[$inputId])) {
                break; // past the bound textboxes (corrupt / trailing) — keep the rest as stored
            }
            $rebuilt[] = $textInputsById[$inputId];
            $used[$inputId] = true;
        }
        foreach ($inputs as $input) {
            if (!isset($used[$input->inputId])) {
                $rebuilt[] = $input; // unbound definitions keep their (trailing) place
                $used[$input->inputId] = true;
            }
        }

        $expandedImageInputs = [];
        foreach ($imageInputs as $input) {
            $expandedImageInputs[] = $input;
            foreach ($clonedImageInputs[$input->inputId] ?? [] as $clone) {
                $expandedImageInputs[] = $clone;
            }
        }

        $canvas['objects'] = $objects;
        $canvas['containers'] = $rawContainers;

        return ['canvas' => $canvas, 'inputs' => $rebuilt, 'imageInputs' => $expandedImageInputs];
    }

    /**
     * Slot k → template-tree id → the LEGACY id slot k takes instead of the
     * derived one ({@see ContainerRepeat::$slots}, written by the editor's
     * "Sloučit do opakovaného řádku"). Only ids of the row's own tree, and
     * only legacy ids no other object / container / row already uses
     * (`$taken`, `$claimed` — updated here).
     *
     * @param array<array-key, mixed> $treeIds the row's deep member ids + tree container ids (as keys)
     * @param array<string, true> $taken
     * @param array<string, true> $claimed
     * @return array<int, array<string, string>>
     */
    private static function legacyIdMaps(CanvasContainer $row, array $treeIds, array $taken, array &$claimed): array
    {
        \assert($row->repeat !== null);
        $maps = [];

        foreach ($row->repeat->slots as $index => $legacy) {
            $slot = $index + 1;
            if ($slot >= $row->repeat->max) {
                break; // a slot the row no longer materialises
            }
            foreach ($legacy as $templateId => $legacyId) {
                if (!isset($treeIds[$templateId]) || isset($taken[$legacyId]) || isset($claimed[$legacyId])) {
                    continue;
                }
                $maps[$slot][$templateId] = $legacyId;
                $claimed[$legacyId] = true;
            }
        }

        return $maps;
    }

    /**
     * Every id the design already uses: object inputIds (group children
     * included) and container ids.
     *
     * @param array<int, mixed> $objects
     * @param array<int, mixed> $containers
     * @return array<string, true>
     */
    private static function idsInDesign(array $objects, array $containers): array
    {
        $ids = [];
        $walk = static function (array $list) use (&$walk, &$ids): void {
            foreach ($list as $object) {
                if (!is_array($object)) {
                    continue;
                }
                if (is_string($object['inputId'] ?? null) && $object['inputId'] !== '') {
                    $ids[$object['inputId']] = true;
                }
                if (is_array($object['objects'] ?? null)) {
                    $walk($object['objects']);
                }
            }
        };
        $walk($objects);

        foreach ($containers as $container) {
            if (is_array($container) && is_string($container['id'] ?? null) && $container['id'] !== '') {
                $ids[$container['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param array<string, string> $legacy template-tree id → this slot's legacy id
     * @return array<array-key, mixed>
     */
    private static function slotDefinition(array $raw, string $rowId, int $slot, array $legacy = []): array
    {
        $originalId = is_string($raw['id'] ?? null) ? $raw['id'] : '';
        $raw['id'] = $legacy[$originalId] ?? self::slotId($rowId, $originalId, $slot);

        foreach (['memberInputIds', 'memberContainerIds'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                $raw[$key] = array_map(
                    static fn (mixed $id): mixed => is_string($id) && $id !== '' ? ($legacy[$id] ?? self::slotId($rowId, $id, $slot)) : $id,
                    $raw[$key],
                );
            }
        }

        // A slot is a plain container of the flow: never repeatable itself
        // (one repeatable per parent), never user-movable (nested).
        unset($raw['repeat'], $raw['repeatSlots'], $raw['userMove']);
        foreach (array_keys($raw) as $key) {
            if (is_string($key) && str_starts_with($key, 'userMove')) {
                unset($raw[$key]);
            }
        }

        if ($originalId === $rowId) {
            $raw['repeatSlotOf'] = $rowId;
        }

        return $raw;
    }

    /**
     * @param array<array-key, mixed> $object
     * @param array<string, string> $legacy template-tree id → this slot's legacy id
     * @return array<array-key, mixed>
     */
    private static function rekeyed(array $object, string $rowId, int $slot, array $legacy = []): array
    {
        if (is_string($object['inputId'] ?? null) && $object['inputId'] !== '') {
            $object['inputId'] = $legacy[$object['inputId']] ?? self::slotId($rowId, $object['inputId'], $slot);
        }

        if (is_array($object['objects'] ?? null)) {
            foreach ($object['objects'] as $i => $child) {
                if (is_array($child)) {
                    $object['objects'][$i] = self::rekeyed($child, $rowId, $slot, $legacy);
                }
            }
        }

        return $object;
    }

    /**
     * @param array<array-key, mixed> $object
     * @return array<array-key, mixed>
     */
    private static function translated(array $object, float $dy): array
    {
        $object['top'] = self::top($object) + $dy;

        // An absolutely positioned clip lives in canvas coordinates.
        if (is_array($object['clipPath'] ?? null) && ($object['clipPath']['absolutePositioned'] ?? false) === true) {
            $object['clipPath']['top'] = self::top($object['clipPath']) + $dy;
        }

        return $object;
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function top(array $object): float
    {
        return is_numeric($object['top'] ?? null) ? (float) $object['top'] : 0.0;
    }

    /**
     * The layout engine's `displayedHeight`: height × scaleY.
     *
     * @param array<array-key, mixed> $object
     */
    private static function height(array $object): float
    {
        $height = is_numeric($object['height'] ?? null) ? (float) $object['height'] : 0.0;
        $scale = is_numeric($object['scaleY'] ?? null) && (float) $object['scaleY'] !== 0.0 ? (float) $object['scaleY'] : 1.0;

        return $height * $scale;
    }

    /**
     * Designed vertical extent [top, bottom] of the VISIBLE objects carrying
     * one of the ids (design-hidden members are not in the flow — the
     * engine's `collectMembers` skips them).
     *
     * @param array<int, mixed> $objects
     * @param array<array-key, mixed> $ids id → anything
     * @return null|array{float, float}
     */
    private static function extentOfIds(array $objects, array $ids): null|array
    {
        $top = null;
        $bottom = null;
        foreach ($objects as $object) {
            if (!is_array($object) || !is_string($object['inputId'] ?? null) || !isset($ids[$object['inputId']])) {
                continue;
            }
            if (($object['visible'] ?? true) === false) {
                continue;
            }
            $objectTop = self::top($object);
            $top = $top === null ? $objectTop : min($top, $objectTop);
            $bottom = $bottom === null ? $objectTop + self::height($object) : max($bottom, $objectTop + self::height($object));
        }

        return $top === null || $bottom === null ? null : [$top, $bottom];
    }

    /**
     * The flow items of a container as id sets: each direct member alone,
     * each child container with its whole tree.
     *
     * @param list<CanvasContainer> $collection
     * @return list<array{container: null|string, ids: array<string, true>}>
     */
    private static function items(array $collection, CanvasContainer $container): array
    {
        $items = [];
        foreach ($container->memberInputIds as $memberId) {
            $items[] = ['container' => null, 'ids' => [$memberId => true]];
        }
        foreach ($container->memberContainerIds as $childId) {
            $child = CanvasContainer::findById($collection, $childId);
            if ($child === null) {
                continue;
            }
            $ids = [];
            foreach ($child->deepMemberInputIds($collection) as $memberId) {
                $ids[$memberId] = true;
            }
            $items[] = ['container' => $childId, 'ids' => $ids];
        }

        return $items;
    }

    /**
     * The designed gap from the row to the nearest item below it in its
     * parent (null when the row is the last item).
     *
     * @param array<int, mixed> $objects
     * @param list<CanvasContainer> $collection
     * @param array{float, float} $rowExtent
     */
    private static function designedGapAfter(array $objects, array $collection, CanvasContainer $parent, CanvasContainer $row, array $rowExtent): null|float
    {
        $nextTop = null;
        foreach (self::items($collection, $parent) as $item) {
            if ($item['container'] === $row->id) {
                continue;
            }
            $extent = self::extentOfIds($objects, $item['ids']);
            if ($extent === null || $extent[0] <= $rowExtent[0]) {
                continue;
            }
            $nextTop = $nextTop === null ? $extent[0] : min($nextTop, $extent[0]);
        }

        return $nextTop === null ? null : $nextTop - $rowExtent[1];
    }

    /**
     * Ids of every object below the branch holding the row: in the row's
     * parent the items designed below the row, in each further ancestor the
     * items designed below the child that holds the row.
     *
     * @param array<int, mixed> $objects
     * @param list<CanvasContainer> $collection
     * @return array<string, true>
     */
    private static function idsBelowBranch(array $objects, array $collection, CanvasContainer $row): array
    {
        $ids = [];
        $branch = $row;
        $seen = [];

        while (($parent = self::parentOf($collection, $branch->id)) !== null && !isset($seen[$parent->id])) {
            $seen[$parent->id] = true;
            $branchIds = array_flip($branch->deepMemberInputIds($collection));
            $branchExtent = self::extentOfIds($objects, $branchIds);

            if ($branchExtent !== null) {
                foreach (self::items($collection, $parent) as $item) {
                    if ($item['container'] === $branch->id) {
                        continue;
                    }
                    $extent = self::extentOfIds($objects, $item['ids']);
                    if ($extent !== null && $extent[0] > $branchExtent[0]) {
                        $ids += $item['ids'];
                    }
                }
            }

            $branch = $parent;
        }

        return $ids;
    }

    /**
     * @param list<CanvasContainer> $collection
     */
    private static function parentOf(array $collection, string $containerId): null|CanvasContainer
    {
        foreach ($collection as $candidate) {
            if ($candidate->id !== $containerId && in_array($containerId, $candidate->memberContainerIds, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The row container and every container nested in it.
     *
     * @param list<CanvasContainer> $collection
     * @return array<string, true>
     */
    private static function treeContainerIds(array $collection, CanvasContainer $row): array
    {
        $ids = [];
        $walk = static function (CanvasContainer $container) use (&$walk, &$ids, $collection): void {
            if (isset($ids[$container->id])) {
                return;
            }
            $ids[$container->id] = true;
            foreach ($container->memberContainerIds as $childId) {
                $child = CanvasContainer::findById($collection, $childId);
                if ($child !== null) {
                    $walk($child);
                }
            }
        };
        $walk($row);

        return $ids;
    }
}
