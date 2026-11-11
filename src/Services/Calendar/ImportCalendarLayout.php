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
 * Plans only — the controller dispatches the copies. The calendar row is not
 * modified: the client applies the layout as one undo step and saves it.
 */
readonly final class ImportCalendarLayout
{
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

        return [
            'layout' => CalendarLayoutAssets::rewrite($layout, $map),
            'copies' => $copies,
        ];
    }
}
