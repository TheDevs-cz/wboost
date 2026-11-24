<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Message\Calendar\CopyCalendarAsset;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\Calendar\CalendarActor;

/**
 * Nahrát layout: remaps the asset references of a layout into the target
 * calendar (docs/calendar/architecture.md §C.7). Every referenced asset that
 * belongs to the source calendar gets a new id and a copy instruction; any
 * other reference becomes null (a layout file whose source calendar is
 * missing, foreign or deleted loads without pictures).
 *
 * The own layers' page lists drop the special-page keys (`sp:<id>`) that mean
 * nothing in the target (K20 P2): kept are the generated pages (fixed ids —
 * the same page in every calendar) and the target's own special pages.
 *
 * Plans only — the controller dispatches the copies. The calendar row is not
 * modified: the client applies the layout as one undo step and saves it.
 */
readonly final class ImportCalendarLayout
{
    /** The generated special pages — the same ids in every calendar (JS `GENERATED_SPECIAL_IDS`). */
    public const array GENERATED_SPECIAL_IDS = ['intro', 'contacts', 'year', 'waste', 'notes'];

    public function __construct(
        private CalendarAssetRepository $assetRepository,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @param null|Calendar $source a calendar of the SAME project, or null
     * @return array{layout: \stdClass, copies: list<CopyCalendarAsset>}
     */
    public function plan(Calendar $target, \stdClass $layout, null|Calendar $source, CalendarActor $actor): array
    {
        $map = [];
        $copies = [];

        if ($source !== null && $source->project->id->equals($target->project->id)) {
            $referenced = array_flip(CalendarLayoutAssets::ids($layout, false));

            foreach ($this->assetRepository->forCalendar($source->id) as $asset) {
                $oldId = strtolower($asset->id->toString());

                if (!isset($referenced[$oldId])) {
                    continue;
                }

                $newId = $this->provideIdentity->next();
                $map[$oldId] = $newId->toString();
                $copies[] = new CopyCalendarAsset($newId, $asset->id, $target->id, $actor);
            }
        }

        $specialPages = $target->content['specialPages'] ?? [];
        $known = array_merge(self::GENERATED_SPECIAL_IDS, is_array($specialPages) ? array_map(strval(...), array_keys($specialPages)) : []);

        return [
            'layout' => self::dropForeignPageKeys(CalendarLayoutAssets::rewrite($layout, $map), $known),
            'copies' => $copies,
        ];
    }

    /**
     * The layout with every `sp:<id>` key whose id is not in `$known` removed
     * from `layers[*].pages` (a list) and `layers[*].except`. A layer whose
     * list ends up empty stays — on no page — for the designer to place.
     * Mirror of `dropForeignPageKeys` in assets/calendar/model/layers.js.
     *
     * @param list<string> $known special-page ids that exist in the target
     */
    public static function dropForeignPageKeys(\stdClass $layout, array $known): \stdClass
    {
        $layers = $layout->layers ?? null;

        if (!$layers instanceof \stdClass) {
            return $layout;
        }

        $keep = static fn (mixed $key): bool => is_string($key) && (!str_starts_with($key, 'sp:') || in_array(substr($key, 3), $known, true));

        foreach (get_object_vars($layers) as $layer) {
            if (!$layer instanceof \stdClass) {
                continue;
            }

            foreach (['pages', 'except'] as $field) {
                if (is_array($layer->{$field} ?? null)) {
                    $layer->{$field} = array_values(array_filter($layer->{$field}, $keep));
                }
            }
        }

        return $layout;
    }
}
