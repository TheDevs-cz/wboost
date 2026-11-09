<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\ShapeColorInput;

/**
 * "One pencil per container" (2026-11-09, container-rows plan P1): which
 * containers of a variant get ONE box + ONE pencil on the fill pages instead
 * of a pencil per member, and what the "container sheet" that pencil opens
 * lists.
 *
 * A sheet OWNER is chosen by {@see self::PENCIL_LEVEL} — today every
 * TOP-LEVEL container (Jan's decision: one pencil per visual block; nested
 * containers become sections inside it). An owner qualifies when its tree
 * holds at least {@see self::MIN_FIELDS} EDITABLE fields (an unlocked text, a
 * fillable image slot, a recolourable shape); the others keep today's
 * per-field pencils. Switching to "one pencil per row" is
 * `PENCIL_LEVEL = LEVEL_ROW`: the fill pages consume only the rows built
 * here (owner id, member ids, ordered entries), so nothing else changes.
 *
 * Every id is the variant's own — on the document page the caller passes the
 * page's re-keyed view ({@see \WBoost\Web\Services\TemplateDocument\DocumentPageView}),
 * so container and field ids are fill keys there.
 *
 * The sheet is a VIEW over the existing "Všechna pole" cards (the same editor
 * instances — see variant_fill_overlay_controller `openSheet`), so nothing
 * here creates a form field.
 */
final readonly class FillContainerSheets
{
    /** One sheet per top-level container; nested containers are sections. */
    public const string LEVEL_TOP = 'top';

    /** One sheet per container that directly holds editable fields (a row). */
    public const string LEVEL_ROW = 'row';

    /** THE switch between the two pencil levels (see the class docblock). */
    public const string PENCIL_LEVEL = self::LEVEL_TOP;

    /** A container with fewer editable fields keeps per-field pencils. */
    public const int MIN_FIELDS = 2;

    public function __construct(
        private TextInputObjectBinder $textInputObjectBinder,
        private CanvasPlaceholderGeometry $placeholderGeometry,
        private FillContainerHides $containerHides,
    ) {
    }

    /**
     * The sheets of the given variants, de-duplicated by owner id (group
     * dimensions share container ids; the first dimension carrying one
     * defines its sheet — the same rule as the popovers).
     *
     * @param list<TemplateVariant> $variants
     * @param array<array-key, mixed> $hiddenValues id → hidden (Live prop / seed)
     * @return list<array{
     *     id: string,
     *     name: null|string,
     *     hidable: bool,
     *     hidden: bool,
     *     memberIds: list<string>,
     *     fieldIds: list<string>,
     *     entries: list<array{kind: string, id: string, depth: int, name?: null|string, hidable?: bool, hidden?: bool}>
     * }>
     */
    public function forVariants(array $variants, array $hiddenValues = [], string $level = self::PENCIL_LEVEL): array
    {
        $hideNames = [];
        foreach ($this->containerHides->placeholders($variants, $hiddenValues) as $row) {
            $hideNames[$row['id']] = $row['name'];
        }

        $sheets = [];
        foreach ($variants as $variant) {
            foreach ($this->sheetsOf($variant, $hiddenValues, $hideNames, $level) as $sheet) {
                $sheets[$sheet['id']] ??= $sheet;
            }
        }

        return array_values($sheets);
    }

    /**
     * @param array<array-key, mixed> $hiddenValues
     * @param array<string, string> $hideNames hidable container id → its toggle label
     * @return list<array{
     *     id: string,
     *     name: null|string,
     *     hidable: bool,
     *     hidden: bool,
     *     memberIds: list<string>,
     *     fieldIds: list<string>,
     *     entries: list<array{kind: string, id: string, depth: int, name?: null|string, hidable?: bool, hidden?: bool}>
     * }>
     */
    private function sheetsOf(TemplateVariant $variant, array $hiddenValues, array $hideNames, string $level): array
    {
        $canvas = json_decode($variant->canvas, true);
        $canvas = is_array($canvas) ? $canvas : [];
        $containers = CanvasContainer::collectionFromCanvas($canvas);
        if ($containers === []) {
            return [];
        }

        $editable = $this->editableIds($variant, $canvas);
        $tops = $this->designedTops($variant, $canvas);
        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] ??= $container;
        }

        $owners = self::owners($containers, $editable, $level);
        $ownerIds = array_fill_keys(array_map(static fn (CanvasContainer $c): string => $c->id, $owners), true);

        $sheets = [];
        foreach ($owners as $owner) {
            $memberIds = [];
            $entries = [];
            $this->walk($owner, 0, $byId, $ownerIds, $editable, $tops, $hiddenValues, $hideNames, $entries, $memberIds, []);

            $fieldIds = [];
            foreach ($entries as $entry) {
                if ($entry['kind'] === 'field') {
                    $fieldIds[] = $entry['id'];
                }
            }
            if (count($fieldIds) < self::MIN_FIELDS) {
                continue;
            }

            $sheets[] = [
                'id' => $owner->id,
                'name' => $owner->name ?? ($hideNames[$owner->id] ?? null),
                'hidable' => $owner->hidable,
                'hidden' => $owner->hidable && self::isHidden($hiddenValues, $owner->id),
                'memberIds' => array_values(array_unique($memberIds)),
                'fieldIds' => $fieldIds,
                'entries' => $entries,
            ];
        }

        return $sheets;
    }

    /**
     * Sheet owners at the requested level.
     *
     * @param list<CanvasContainer> $containers
     * @param array<string, true> $editable
     * @return list<CanvasContainer>
     */
    private static function owners(array $containers, array $editable, string $level): array
    {
        if ($level === self::LEVEL_ROW) {
            return array_values(array_filter(
                $containers,
                static function (CanvasContainer $container) use ($editable): bool {
                    foreach ($container->memberInputIds as $id) {
                        if (isset($editable[$id])) {
                            return true;
                        }
                    }

                    return false;
                },
            ));
        }

        return array_values(array_filter(
            $containers,
            static fn (CanvasContainer $container): bool => !$container->isNestedIn($containers),
        ));
    }

    /**
     * Depth-first over one owner's tree in DESIGNED flow order (top to
     * bottom): editable members become `field` entries, nested containers
     * that are not owners themselves become `section` entries followed by
     * their own fields (a section without any field is dropped).
     *
     * @param array<string, CanvasContainer> $byId
     * @param array<string, true> $ownerIds
     * @param array<string, true> $editable
     * @param array<string, float> $tops
     * @param array<array-key, mixed> $hiddenValues
     * @param array<string, string> $hideNames
     * @param list<array{kind: string, id: string, depth: int, name?: null|string, hidable?: bool, hidden?: bool}> $entries
     * @param list<string> $memberIds
     * @param array<string, true> $visiting
     */
    private function walk(
        CanvasContainer $container,
        int $depth,
        array $byId,
        array $ownerIds,
        array $editable,
        array $tops,
        array $hiddenValues,
        array $hideNames,
        array &$entries,
        array &$memberIds,
        array $visiting,
    ): void {
        $visiting[$container->id] = true;

        /** @var list<array{top: float, order: int, kind: string, id: string}> $items */
        $items = [];
        foreach ($container->memberInputIds as $id) {
            $memberIds[] = $id;
            if (isset($editable[$id])) {
                $items[] = ['top' => $tops[$id] ?? INF, 'order' => count($items), 'kind' => 'field', 'id' => $id];
            }
        }
        foreach ($container->memberContainerIds as $childId) {
            $child = $byId[$childId] ?? null;
            if ($child === null || isset($visiting[$childId]) || isset($ownerIds[$childId])) {
                continue;
            }
            $items[] = ['top' => self::treeTop($child, $byId, $tops), 'order' => count($items), 'kind' => 'container', 'id' => $childId];
        }

        usort($items, static fn (array $a, array $b): int => [$a['top'], $a['order']] <=> [$b['top'], $b['order']]);

        foreach ($items as $item) {
            if ($item['kind'] === 'field') {
                $entries[] = ['kind' => 'field', 'id' => $item['id'], 'depth' => $depth];
                continue;
            }

            $child = $byId[$item['id']];
            $sectionAt = count($entries);
            $entries[] = [
                'kind' => 'section',
                'id' => $child->id,
                'depth' => $depth,
                'name' => $child->name ?? ($hideNames[$child->id] ?? null),
                'hidable' => $child->hidable,
                'hidden' => $child->hidable && self::isHidden($hiddenValues, $child->id),
            ];
            $before = count($entries);
            $this->walk($child, $depth + 1, $byId, $ownerIds, $editable, $tops, $hiddenValues, $hideNames, $entries, $memberIds, $visiting);
            if (count($entries) === $before) {
                // Nothing editable inside: no empty heading.
                array_splice($entries, $sectionAt, 1);
            }
        }
    }

    /**
     * @param array<string, CanvasContainer> $byId
     * @param array<string, float> $tops
     */
    private static function treeTop(CanvasContainer $container, array $byId, array $tops): float
    {
        $top = INF;
        foreach ($container->deepMemberInputIds(array_values($byId)) as $id) {
            $top = min($top, $tops[$id] ?? INF);
        }

        return $top;
    }

    /**
     * Unlocked texts, fillable (non-background) image slots, recolourable
     * shapes.
     *
     * @param array<array-key, mixed> $canvas
     * @return array<string, true>
     */
    private function editableIds(TemplateVariant $variant, array $canvas): array
    {
        $ids = [];
        foreach ($variant->inputs as $input) {
            if (!$input->locked) {
                $ids[$input->inputId] = true;
            }
        }
        foreach ($variant->imageInputs as $input) {
            if (!$input->isBackground) {
                $ids[$input->inputId] = true;
            }
        }
        foreach (ShapeColorInput::collectionFromCanvas($canvas) as $input) {
            $ids[$input->inputId] = true;
        }

        return $ids;
    }

    /**
     * Designed top (canvas px) of every member candidate: texts through the
     * positional binding (a textbox's own `inputId` is unreliable), anything
     * else by its object's `inputId`.
     *
     * @param array<array-key, mixed> $canvas
     * @return array<string, float>
     */
    private function designedTops(TemplateVariant $variant, array $canvas): array
    {
        $tops = [];
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }
            $type = $object['type'] ?? null;
            if (is_string($type) && strtolower($type) === 'textbox') {
                continue;
            }
            $id = $object['inputId'] ?? null;
            $frame = is_string($id) ? $this->placeholderGeometry->frameFromObject($object) : null;
            if (is_string($id) && $frame !== null) {
                $tops[$id] ??= $frame->y;
            }
        }
        foreach ($this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs) as $id => $frame) {
            $tops[$id] = $frame->y;
        }

        return $tops;
    }

    /**
     * @param array<array-key, mixed> $hiddenValues
     */
    private static function isHidden(array $hiddenValues, string $id): bool
    {
        return in_array($hiddenValues[$id] ?? false, [true, 1, '1', 'true'], true);
    }
}
