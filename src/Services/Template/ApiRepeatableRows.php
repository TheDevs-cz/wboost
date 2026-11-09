<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Value\AppliedApiRows;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\ShapeColorInput;

/**
 * Repeatable container rows over the REST API (container-rows plan P5).
 *
 * The API never asks a consumer to derive slot ids: a render body sends
 *
 *     "rows": {"<rowContainerId>": [ {"<templateMemberId>": value, …}, … ]}
 *
 * — one object per row, keyed by the TEMPLATE row's member ids (texts, image
 * slots, recolourable shapes, hidable containers of the row tree), values in
 * the shape `inputs` / `images` / `shapeColors` already take. The list length
 * IS the row count. {@see apply()} validates it STRICTLY and moves every
 * entry onto the row's slot ids of the EXPANDED variant
 * ({@see RepeatableRows::expand()}) — after that, the ordinary strict
 * resolvers and the renderer handle the values exactly like the web fill's.
 *
 * Slot ids are always READ from the expanded design (the slot container of
 * row k and its members, paired positionally with the template row's tree),
 * never re-derived here, so whatever id scheme the expansion uses — the
 * uuid5 slot ids or legacy ids a merged design keeps — the listing
 * ({@see describe()}), the bodies and the history all agree with it.
 *
 * {@see fold()} is the inverse, for the export-history API: a stored fill
 * (slot-id space) back into `rows`.
 */
final readonly class ApiRepeatableRows
{
    public const string KIND_TEXT = 'text';
    public const string KIND_IMAGE = 'image';
    public const string KIND_SHAPE = 'shape';
    public const string KIND_CONTAINER = 'container';

    /**
     * Every repeatable row of the variant, as the API publishes it: the row,
     * its parent, its FILLABLE members (unlocked texts, non-background image
     * slots, recolourable shapes, hidable containers of the row tree — the
     * row itself included) and, per materialised slot (slot 0 = the template
     * row with its original ids), the id each member carries in that slot.
     *
     * @return list<array{row: CanvasContainer, parentId: null|string, members: list<array{id: string, kind: string, name: null|string}>, slots: list<array{index: int, containerId: string, ids: array<string, string>}>}>
     */
    public static function describe(TemplateVariant $variant): array
    {
        $expanded = RepeatableRows::expand($variant);

        if (!RepeatableRows::hasRepeatableRows($expanded)) {
            return [];
        }

        $decoded = json_decode($expanded->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $collection = CanvasContainer::collectionFromCanvas($canvas);

        /** @var array<string, CanvasContainer> $byId */
        $byId = [];
        foreach ($collection as $container) {
            $byId[$container->id] ??= $container;
        }

        /** @var array<string, array{string, null|string}> $fillable member id → [kind, name] */
        $fillable = [];
        foreach ($expanded->inputs as $input) {
            if (!$input->locked) {
                $fillable[$input->inputId] ??= [self::KIND_TEXT, $input->name];
            }
        }
        foreach ($expanded->imageInputs as $input) {
            if (!$input->isBackground) {
                $fillable[$input->inputId] ??= [self::KIND_IMAGE, $input->name];
            }
        }
        foreach (ShapeColorInput::collectionFromCanvas($canvas) as $shape) {
            $fillable[$shape->inputId] ??= [self::KIND_SHAPE, $shape->name];
        }

        $rows = [];
        foreach ($collection as $row) {
            if ($row->repeat === null || $row->repeatSlotIds === []) {
                continue;
            }

            $parentId = null;
            foreach ($collection as $candidate) {
                if ($candidate->id !== $row->id && in_array($row->id, $candidate->memberContainerIds, true)) {
                    $parentId = $candidate->id;
                    break;
                }
            }

            $members = [];
            self::collectMembers($row, $byId, $fillable, $members, []);

            $slots = [];
            foreach ($row->repeatSlotIds as $index => $slotContainerId) {
                $slot = $byId[$slotContainerId] ?? null;
                $pairs = [];
                if ($slot !== null) {
                    self::pair($row, $slot, $byId, $pairs, []);
                }

                // The row container itself is always mapped, so `ids` is
                // never an empty map.
                $ids = [$row->id => $slotContainerId];
                foreach ($members as $member) {
                    if (isset($pairs[$member['id']])) {
                        $ids[$member['id']] = $pairs[$member['id']];
                    }
                }

                $slots[] = ['index' => $index, 'containerId' => $slotContainerId, 'ids' => $ids];
            }

            $rows[] = ['row' => $row, 'parentId' => $parentId, 'members' => $members, 'slots' => $slots];
        }

        return $rows;
    }

    /**
     * A body's `rows` folded into its value maps (see the class docblock),
     * STRICTLY:
     *
     * - `rows` must be an object keyed by row container id, each value a
     *   list of row objects (else `invalid_value`);
     * - an id that is no repeatable row of the variant → `not_repeatable`
     *   (a GROUP member skips ids it does not carry at all — the group call
     *   checks them against every member, {@see assertKnownInGroup()});
     * - the list length must be within `[min, max]` → `too_few_rows` /
     *   `too_many_rows`;
     * - a member of a sent row may not be addressed directly as well →
     *   `ambiguous_row_value` (Jan, 2026-11-09: never silently pick);
     * - a row entry key that is no fillable member → `unknown_row_input`.
     *
     * Without `rows` the maps pass through untouched (every row shows its
     * designed default, values addressed to slot ids keep working) — except
     * that a row the body addresses BEYOND its default by slot id shows those
     * rows ({@see RepeatableRows::inferredCounts()}): the case of a row merged
     * from hand-numbered copies (P6), whose old ids ("Střelec 3") callers
     * keep sending. The same holds for a row a body's `rows` leaves out.
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $shapeColors
     *
     * @throws InvalidExportValue
     */
    public static function apply(TemplateVariant $variant, mixed $rows, array $inputs, array $images, array $shapeColors, bool $groupMember = false): AppliedApiRows
    {
        $inputs = self::stringKeyed($inputs);
        $images = self::stringKeyed($images);
        $shapeColors = self::stringKeyed($shapeColors);

        if ($rows === null || $rows === []) {
            return new AppliedApiRows($inputs, $images, $shapeColors, self::inferredCounts($variant, $inputs, $images, $shapeColors));
        }

        if (!is_array($rows) || array_is_list($rows)) {
            throw InvalidExportValue::invalidValue('`rows` must be an object keyed by row container id (`repeatableRows[].containerId`), each value a list of row objects.');
        }

        $described = [];
        foreach (self::describe($variant) as $row) {
            $described[$row['row']->id] = $row;
        }

        $containerIds = [];
        if ($groupMember) {
            $decoded = json_decode(RepeatableRows::expand($variant)->canvas, true);
            foreach (CanvasContainer::collectionFromCanvas(is_array($decoded) ? $decoded : []) as $container) {
                $containerIds[$container->id] = true;
            }
        }

        $counts = [];
        $origins = [];

        foreach ($rows as $rowId => $entries) {
            $rowId = (string) $rowId;
            $row = $described[$rowId] ?? null;

            if ($row === null) {
                if ($groupMember && !isset($containerIds[$rowId])) {
                    continue; // another dimension's row — validated group-wide
                }

                throw InvalidExportValue::notRepeatable($rowId);
            }

            $repeat = $row['row']->repeat;
            \assert($repeat !== null);

            if (!is_array($entries) || !array_is_list($entries)) {
                throw InvalidExportValue::invalidValue(sprintf('rows.%s must be a list of row objects (one per row, the list length is the row count).', $rowId))
                    ->withContext(['containerId' => $rowId]);
            }

            $count = count($entries);
            if ($count > $repeat->max) {
                throw InvalidExportValue::tooManyRows($rowId, $count, $repeat->min, $repeat->max);
            }
            if ($count < $repeat->min) {
                throw InvalidExportValue::tooFewRows($rowId, $count, $repeat->min, $repeat->max);
            }

            /** @var array<string, string> $kinds */
            $kinds = [];
            foreach ($row['members'] as $member) {
                $kinds[$member['id']] = $member['kind'];
            }

            // The whole row is the body's: any of its slot ids addressed
            // directly too is ambiguous.
            foreach ($row['slots'] as $slot) {
                foreach ($slot['ids'] as $templateId => $slotId) {
                    $kind = $kinds[$templateId] ?? null;
                    if ($kind !== null && array_key_exists($slotId, self::mapFor($kind, $inputs, $images, $shapeColors))) {
                        throw InvalidExportValue::ambiguousRowValue($rowId, $slot['index'], $slotId);
                    }
                }
            }

            foreach ($entries as $index => $entry) {
                if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                    throw InvalidExportValue::invalidValue(sprintf('rows.%s[%d] must be an object keyed by the row\'s member ids.', $rowId, $index))
                        ->withContext(['containerId' => $rowId, 'rowIndex' => $index]);
                }

                $ids = $row['slots'][$index]['ids'];

                foreach ($entry as $templateId => $value) {
                    $templateId = (string) $templateId;
                    $kind = $kinds[$templateId] ?? null;
                    $slotId = $ids[$templateId] ?? null;

                    if ($kind === null || $slotId === null) {
                        throw InvalidExportValue::unknownRowInput($rowId, $index, $templateId);
                    }

                    if ($kind === self::KIND_IMAGE) {
                        $images[$slotId] = $value;
                    } elseif ($kind === self::KIND_SHAPE) {
                        $shapeColors[$slotId] = $value;
                    } else {
                        $inputs[$slotId] = $value; // texts + container hides
                    }
                }
            }

            foreach ($row['slots'] as $slot) {
                if ($slot['index'] >= $count) {
                    break;
                }
                foreach ($slot['ids'] as $templateId => $slotId) {
                    $origins[$slotId] = [$rowId, $slot['index'], $templateId];
                }
            }

            $counts[$rowId] = $count;
        }

        return new AppliedApiRows($inputs, $images, $shapeColors, $counts + self::inferredCounts($variant, $inputs, $images, $shapeColors), $origins);
    }

    /**
     * Rows addressed past their default by slot id (a merged row's legacy
     * ids) — see {@see apply()}.
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $images
     * @param array<string, mixed> $shapeColors
     * @return array<string, int>
     */
    private static function inferredCounts(TemplateVariant $variant, array $inputs, array $images, array $shapeColors): array
    {
        return RepeatableRows::inferredCounts(
            [RepeatableRows::expand($variant)],
            [...array_keys($inputs), ...array_keys($images), ...array_keys($shapeColors)],
        );
    }

    /**
     * A body's raw `rows` (untyped on the request DTOs, so a wrong type
     * answers this structured `invalid_value` instead of the serializer's
     * generic 400). Null = absent.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidExportValue
     */
    public static function raw(mixed $rows): array
    {
        if ($rows === null) {
            return [];
        }

        if (!is_array($rows) || ($rows !== [] && array_is_list($rows))) {
            throw InvalidExportValue::invalidValue('`rows` must be an object keyed by row container id (`repeatableRows[].containerId`), each value a list of row objects.');
        }

        return $rows;
    }

    /**
     * A GROUP body's `rows`: every key must be a repeatable row of at least
     * one member (members share their row ids — group sync keeps `repeat`
     * identical on every size).
     *
     * @param list<TemplateVariant> $members
     *
     * @throws InvalidExportValue
     */
    public static function assertKnownInGroup(array $members, mixed $rows): void
    {
        if (!is_array($rows) || $rows === [] || array_is_list($rows)) {
            return; // shape errors are reported by apply()
        }

        $known = [];
        foreach ($members as $member) {
            foreach (RepeatableRows::repeatableContainers($member) as $container) {
                $known[$container->id] = true;
            }
        }

        foreach (array_keys($rows) as $rowId) {
            if (!isset($known[(string) $rowId])) {
                throw InvalidExportValue::notRepeatable((string) $rowId);
            }
        }
    }

    /**
     * The group's ONE fill for its version: the body applied to every member
     * (shared ids — the maps agree), unioned. 400s carry the member's
     * `variantId`.
     *
     * @param list<TemplateVariant> $members
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $shapeColors
     *
     * @throws InvalidExportValue
     */
    public static function applyToGroup(array $members, mixed $rows, array $inputs, array $images, array $shapeColors): AppliedApiRows
    {
        self::assertKnownInGroup($members, $rows);

        $merged = new AppliedApiRows(self::stringKeyed($inputs), self::stringKeyed($images), self::stringKeyed($shapeColors));

        foreach ($members as $member) {
            try {
                $applied = self::apply($member, $rows, $inputs, $images, $shapeColors, groupMember: true);
            } catch (InvalidExportValue $invalid) {
                throw $invalid->withContext(['variantId' => $member->id->toString()]);
            }

            $merged = new AppliedApiRows(
                $merged->inputs + $applied->inputs,
                $merged->images + $applied->images,
                $merged->shapeColors + $applied->shapeColors,
                $merged->counts + $applied->counts,
                $merged->origins + $applied->origins,
            );
        }

        return $merged;
    }

    /**
     * The inverse of {@see apply()} for the export-history API: a seeded fill
     * (values under slot ids) → the request shape with `rows`. Every
     * repeatable row of the variants (a group's members share ids — first
     * wins) gets `count` row objects (`$counts`, else the default), each
     * holding the member values the fill has for that slot; every slot id is
     * taken OUT of the flat maps (unused slots' values are dropped), so the
     * result can be posted back as-is without `ambiguous_row_value`.
     *
     * @param list<TemplateVariant> $variants
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $images
     * @param array<string, mixed> $shapeColors
     * @param array<string, int> $counts
     * @return array{inputs: array<string, mixed>, images: array<string, mixed>, shapeColors: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}
     */
    public static function fold(array $variants, array $inputs, array $images, array $shapeColors, array $counts): array
    {
        $rows = [];

        foreach ($variants as $variant) {
            foreach (self::describe($variant) as $row) {
                $rowId = $row['row']->id;
                if (isset($rows[$rowId])) {
                    continue;
                }

                $repeat = $row['row']->repeat;
                \assert($repeat !== null);
                $count = $repeat->clamp($counts[$rowId] ?? null);

                $kinds = [];
                foreach ($row['members'] as $member) {
                    $kinds[$member['id']] = $member['kind'];
                }

                $entries = [];
                foreach ($row['slots'] as $slot) {
                    $entry = [];
                    foreach ($slot['ids'] as $templateId => $slotId) {
                        $kind = $kinds[$templateId] ?? null;
                        if ($kind === null) {
                            continue;
                        }

                        $value = match ($kind) {
                            self::KIND_IMAGE => $images[$slotId] ?? null,
                            self::KIND_SHAPE => $shapeColors[$slotId] ?? null,
                            default => $inputs[$slotId] ?? null,
                        };

                        if ($value !== null && $slot['index'] < $count) {
                            $entry[$templateId] = $value;
                        }

                        unset($inputs[$slotId], $images[$slotId], $shapeColors[$slotId]);
                    }

                    if ($slot['index'] < $count) {
                        $entries[] = $entry;
                    }
                }

                $rows[$rowId] = $entries;
            }
        }

        return ['inputs' => $inputs, 'images' => $images, 'shapeColors' => $shapeColors, 'rows' => $rows];
    }

    /**
     * @param array<string, CanvasContainer> $byId
     * @param array<string, array{string, null|string}> $fillable
     * @param list<array{id: string, kind: string, name: null|string}> $members
     * @param array<string, true> $seen
     */
    private static function collectMembers(CanvasContainer $container, array $byId, array $fillable, array &$members, array $seen): void
    {
        if (isset($seen[$container->id])) {
            return;
        }
        $seen[$container->id] = true;

        if ($container->hidable) {
            $members[] = ['id' => $container->id, 'kind' => self::KIND_CONTAINER, 'name' => $container->name];
        }

        foreach ($container->memberInputIds as $memberId) {
            if (isset($fillable[$memberId])) {
                $members[] = ['id' => $memberId, 'kind' => $fillable[$memberId][0], 'name' => $fillable[$memberId][1]];
            }
        }

        foreach ($container->memberContainerIds as $childId) {
            $child = $byId[$childId] ?? null;
            if ($child !== null) {
                self::collectMembers($child, $byId, $fillable, $members, $seen);
            }
        }
    }

    /**
     * Template-row tree ↔ slot tree, positionally (a slot definition is the
     * template's with every id mapped, order kept): template id → slot id
     * for the containers and their member objects.
     *
     * @param array<string, CanvasContainer> $byId
     * @param array<string, string> $pairs
     * @param array<string, true> $seen
     */
    private static function pair(CanvasContainer $template, CanvasContainer $slot, array $byId, array &$pairs, array $seen): void
    {
        if (isset($seen[$template->id])) {
            return;
        }
        $seen[$template->id] = true;

        $pairs[$template->id] = $slot->id;

        foreach ($template->memberInputIds as $i => $memberId) {
            $slotMemberId = $slot->memberInputIds[$i] ?? null;
            if ($slotMemberId !== null) {
                $pairs[$memberId] = $slotMemberId;
            }
        }

        foreach ($template->memberContainerIds as $i => $childId) {
            $templateChild = $byId[$childId] ?? null;
            $slotChildId = $slot->memberContainerIds[$i] ?? null;
            $slotChild = $slotChildId === null ? null : ($byId[$slotChildId] ?? null);
            if ($templateChild !== null && $slotChild !== null) {
                self::pair($templateChild, $slotChild, $byId, $pairs, $seen);
            }
        }
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $images
     * @param array<string, mixed> $shapeColors
     * @return array<string, mixed>
     */
    private static function mapFor(string $kind, array $inputs, array $images, array $shapeColors): array
    {
        return match ($kind) {
            self::KIND_IMAGE => $images,
            self::KIND_SHAPE => $shapeColors,
            default => $inputs,
        };
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
