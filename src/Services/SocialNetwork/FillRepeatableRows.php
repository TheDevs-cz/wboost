<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Value\CanvasContainer;

/**
 * The fill pages' view of the repeatable rows (container-rows plan P4) —
 * everything the client needs to add / remove / reorder rows WITHOUT
 * re-deriving a single id in JS:
 *
 * - `roles`: per ROLE (an id of the designed row's tree — the row container
 *   itself, every nested container, every member object) the id that role
 *   carries in each materialised slot, slot 0 first. A row action is a
 *   permutation of the slots, so the client rewrites every named fill field
 *   (and the picture state) whose key holds a slot id with the same role's
 *   id of another slot — texts, fonts, member / container hides, shape
 *   colours, image picks + placements all move together, generically.
 * - `pristine`: per TEXT role the value an untouched row shows (the template
 *   row's sample — a new row starts from it, Jan 2026-11-09); every other
 *   field's pristine value is "empty / designed".
 * - `capacity`: the server's designed-geometry estimate of how many rows fit
 *   (the SMALLEST member decides on a group — {@see RepeatableRows::canAddRow()}).
 *   The client gate measures the real wrapped heights; this is its fallback
 *   when it cannot measure.
 *
 * The roles are matched POSITIONALLY over each slot's tree walk (container
 * id, own members, then each child's tree) — the expansion clones a row's
 * definitions verbatim with derived ids, and the document page's re-keying
 * maps ids one to one, so the walk has the same shape in every slot and the
 * payload is correct in fill-key space too (the caller passes the page's
 * re-keyed clone there).
 *
 * Variants without a repeatable row contribute nothing: every other design
 * renders no row payload at all.
 */
final class FillRepeatableRows
{
    /**
     * @param list<TemplateVariant> $variants the page's variants (a group's
     *   members, a document page's re-keyed clone) — expanded here when not yet
     * @param array<string, int> $rowCounts the current (normalised) counts
     * @return array<string, array{
     *     id: string,
     *     name: null|string,
     *     min: int,
     *     max: int,
     *     default: int,
     *     count: int,
     *     capacity: int,
     *     slots: list<string>,
     *     roles: array<string, list<string>>,
     *     pristine: array<string, string>
     * }>
     */
    public static function forVariants(array $variants, array $rowCounts): array
    {
        $rows = [];

        foreach ($variants as $variant) {
            $expanded = RepeatableRows::expand($variant);
            $decoded = json_decode($expanded->canvas, true);
            $containers = CanvasContainer::collectionFromCanvas(is_array($decoded) ? $decoded : []);

            $byId = [];
            foreach ($containers as $container) {
                $byId[$container->id] ??= $container;
            }

            $samples = [];
            foreach ($expanded->inputs as $input) {
                $samples[$input->inputId] ??= $input->sampleValue ?? '';
            }

            foreach ($containers as $container) {
                $repeat = $container->repeat;
                if ($repeat === null || $container->repeatSlotIds === []) {
                    continue;
                }

                $capacity = RepeatableRows::designedCapacity($expanded, $container->id, array_diff_key($rowCounts, [$container->id => true]));

                if (isset($rows[$container->id])) {
                    // A group's other dimension: the smallest size decides.
                    if ($capacity !== null) {
                        $rows[$container->id]['capacity'] = min($rows[$container->id]['capacity'], $capacity);
                    }
                    continue;
                }

                $roles = self::roles($container, $byId);
                if ($roles === null) {
                    continue;
                }

                $pristine = [];
                foreach (array_keys($roles) as $role) {
                    if (isset($samples[$role])) {
                        $pristine[$role] = $samples[$role];
                    }
                }

                $rows[$container->id] = [
                    'id' => $container->id,
                    'name' => $container->name,
                    'min' => $repeat->min,
                    'max' => $repeat->max,
                    'default' => $repeat->default,
                    'count' => $repeat->clamp($rowCounts[$container->id] ?? null),
                    'capacity' => $capacity ?? $repeat->max,
                    'slots' => $container->repeatSlotIds,
                    'roles' => $roles,
                    'pristine' => $pristine,
                ];
            }
        }

        return $rows;
    }

    /**
     * Every id of the rows → its row, role and slot (`slot` 0-based) — what
     * the "Všechna pole" arrangement and the version labels need.
     *
     * @param array<string, array{id: string, roles: array<string, list<string>>}> $rows {@see forVariants()}
     * @return array<string, array{row: string, role: string, slot: int}>
     */
    public static function slotIndex(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            foreach ($row['roles'] as $role => $ids) {
                foreach ($ids as $slot => $id) {
                    $index[$id] ??= ['row' => $row['id'], 'role' => (string) $role, 'slot' => $slot];
                }
            }
        }

        return $index;
    }

    /**
     * Role id (slot 0's id) → the id in every slot, or null when a slot's
     * tree does not have the template row's shape (defensive — cannot happen
     * for an expansion).
     *
     * @param array<string, CanvasContainer> $byId
     * @return null|array<string, list<string>>
     */
    private static function roles(CanvasContainer $row, array $byId): null|array
    {
        $walks = [];
        foreach ($row->repeatSlotIds as $slotContainerId) {
            $slot = $byId[$slotContainerId] ?? null;
            if ($slot === null) {
                return null;
            }
            $walks[] = self::treeIds($slot, $byId, []);
        }

        $template = $walks[0] ?? [];
        $roles = [];
        foreach ($template as $position => $roleId) {
            $roles[$roleId] = [];
            foreach ($walks as $walk) {
                if (count($walk) !== count($template)) {
                    return null;
                }
                $roles[$roleId][] = $walk[$position];
            }
        }

        return $roles;
    }

    /**
     * The container id, its own members, then each child's tree.
     *
     * @param array<string, CanvasContainer> $byId
     * @param array<string, true> $visiting
     * @return list<string>
     */
    private static function treeIds(CanvasContainer $container, array $byId, array $visiting): array
    {
        $visiting[$container->id] = true;
        $ids = [$container->id, ...$container->memberInputIds];
        foreach ($container->memberContainerIds as $childId) {
            $child = $byId[$childId] ?? null;
            if ($child === null || isset($visiting[$childId])) {
                continue;
            }
            $ids = [...$ids, ...self::treeIds($child, $byId, $visiting)];
        }

        return $ids;
    }
}
