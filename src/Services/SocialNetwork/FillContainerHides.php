<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\CanvasContainer;

/**
 * The fill pages' view of the HIDABLE containers — one toggle row each in the
 * "Všechna pole" panel, unified across the given variants by container id
 * (group members and a document's pages share ids; first wins, like the text
 * popovers).
 *
 * The toggle writes `hiddenValues[<containerId>]` — the very field family the
 * input hides use — so the Live state hash, the form POST, drafts and export
 * versions carry it with no extra plumbing; the renderer expands it into the
 * container's members ({@see CanvasContainer::hiddenMemberInputIds()}).
 */
final readonly class FillContainerHides
{
    /**
     * @param list<TemplateVariant> $variants
     * @param array<array-key, mixed> $hiddenValues id → hidden (Live prop / seed)
     *
     * @return list<array{id: string, name: string, hidden: bool, memberCount: int}>
     */
    public function placeholders(array $variants, array $hiddenValues): array
    {
        $rows = [];
        $names = [];
        $position = 0;

        foreach ($variants as $variant) {
            $containers = self::containersOf($variant);

            foreach ($containers as $container) {
                if (!$container->hidable || isset($rows[$container->id])) {
                    continue;
                }

                $slot = self::rowSlot($container, $containers);
                if ($slot === null || $slot === 0) {
                    $position++;
                    $names[$container->repeatSlotOf ?? $container->id] = $container->name ?? sprintf('Skupina prvků %d', $position);
                }
                $name = $names[$container->repeatSlotOf ?? $container->id] ?? ($container->name ?? sprintf('Skupina prvků %d', $position));
                $rows[$container->id] = [
                    'id' => $container->id,
                    // A hidable repeatable row (container-rows P4): one toggle
                    // per materialised row, named after its row — the fill
                    // pages show only the active rows' toggles.
                    'name' => $slot === null ? $name : sprintf('%s · řádek %d', $name, $slot + 1),
                    'hidden' => in_array($hiddenValues[$container->id] ?? false, [true, 1, '1', 'true'], true),
                    'memberCount' => count($container->deepMemberInputIds($containers)),
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * Hidable container id → deep member inputIds of ONE variant (the
     * client-side reflow / echo mirror of the render's expansion).
     *
     * @return array<string, list<string>>
     */
    public function memberMap(TemplateVariant $variant): array
    {
        return CanvasContainer::hidableMemberMap(self::containersOf($variant));
    }

    /**
     * The 0-based slot of a repeatable row's materialised container (the
     * row itself is slot 0) on an EXPANDED variant; null for every other
     * container.
     *
     * @param list<CanvasContainer> $containers
     */
    private static function rowSlot(CanvasContainer $container, array $containers): null|int
    {
        if ($container->repeat !== null && $container->repeatSlotIds !== []) {
            return 0;
        }
        if ($container->repeatSlotOf === null) {
            return null;
        }
        $row = CanvasContainer::findById($containers, $container->repeatSlotOf);
        $slot = $row === null ? false : array_search($container->id, $row->repeatSlotIds, true);

        return is_int($slot) ? $slot : null;
    }

    /**
     * @return list<CanvasContainer>
     */
    private static function containersOf(TemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);

        return is_array($decoded) ? CanvasContainer::collectionFromCanvas($decoded) : [];
    }
}
