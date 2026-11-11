<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\Calendar\RecordCalendarHistory;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarAccess;
use WBoost\Web\Services\Calendar\CalendarDesignRules;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Calendar\CalendarLayoutAssets;
use WBoost\Web\Services\Calendar\CalendarResponses;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;
use WBoost\Web\Services\Calendar\ImportCalendarLayout;
use WBoost\Web\Services\Security\CalendarVoter;
use WBoost\Web\Value\Calendar\CalendarActor;

/**
 * Nahrát layout (docs/calendar/architecture.md §C.7): `_token` and either
 * `from` (another calendar of the same project and type) or `layout` (JSON
 * `{ layout, sourceCalendar?, type? }` — a parsed layout file). The pictures
 * the layout uses are copied into this calendar under new ids; the answer is
 * `{ layout, assets, name }`. The calendar itself is NOT changed — the
 * workspace applies the layout as one undo step and saves it.
 */
final class ImportCalendarLayoutController extends AbstractController
{
    public const string NOT_A_LAYOUT = 'Soubor neobsahuje layout kalendáře';
    public const string OTHER_TYPE = 'Layout je pro jiný typ kalendáře.';

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private CalendarAccess $access,
        readonly private CalendarRepository $calendarRepository,
        readonly private CalendarAssetRepository $assetRepository,
        readonly private CalendarDesignRules $designRules,
        readonly private ImportCalendarLayout $importLayout,
        readonly private CalendarDocument $document,
    ) {
    }

    #[Route(path: '/calendar/{calendarId}/layout-import', name: 'calendar_layout_import', requirements: ['calendarId' => Calendar::ID_REQUIREMENT], methods: ['POST'])]
    #[IsGranted(CalendarVoter::EDIT, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(CalendarDocument::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return CalendarResponses::csrfExpired();
        }

        $actor = $this->access->forUser($calendar);
        $from = $request->request->getString('from');

        return CalendarResponses::guard(function () use ($calendar, $request, $actor, $from): JsonResponse {
            if ($from !== '') {
                $source = $this->sibling($calendar, $from);

                if ($source === null) {
                    return CalendarResponses::error('Kalendář nenalezen.', Response::HTTP_NOT_FOUND);
                }

                if ($source->type !== $calendar->type) {
                    throw new InvalidCalendarChange(self::OTHER_TYPE);
                }

                if ($source->layout === null) {
                    throw new InvalidCalendarChange('Kalendář zatím nemá layout.');
                }

                return $this->import($calendar, $source->layout, $source, $actor, $source->name, DescribeCalendarChange::layoutFromCalendar($source->name));
            }

            [$layout, $source] = $this->fromFile($calendar, $request->request->getString('layout'));

            return $this->import($calendar, $layout, $source, $actor, null, DescribeCalendarChange::LAYOUT_FROM_FILE);
        });
    }

    private function import(Calendar $calendar, \stdClass $layout, null|Calendar $source, CalendarActor $actor, null|string $name, string $history): JsonResponse
    {
        $plan = $this->importLayout->plan($calendar, $layout, $source, $actor);
        $assets = [];

        foreach ($plan['copies'] as $copy) {
            $this->bus->dispatch($copy);
            $assets[$copy->newAssetId->toString()] = $this->document->asset($this->assetRepository->get($copy->newAssetId));
        }

        $this->bus->dispatch(new RecordCalendarHistory($calendar->id, $actor, $history));

        return new JsonResponse([
            'layout' => $plan['layout'],
            'assets' => (object) $assets,
            'name' => $name,
        ]);
    }

    /**
     * @return array{\stdClass, null|Calendar}
     *
     * @throws InvalidCalendarChange
     */
    private function fromFile(Calendar $calendar, string $json): array
    {
        if ($json === '' || strlen($json) > CalendarDesignRules::MAX_LAYOUT_BYTES) {
            throw new InvalidCalendarChange(self::NOT_A_LAYOUT);
        }

        $file = json_decode($json, false, 64);

        if (!$file instanceof \stdClass || !($file->layout ?? null) instanceof \stdClass) {
            throw new InvalidCalendarChange(self::NOT_A_LAYOUT);
        }

        if (isset($file->type) && $file->type !== $calendar->type->value) {
            throw new InvalidCalendarChange(self::OTHER_TYPE);
        }

        try {
            $layout = $this->designRules->layout($file->layout);
        } catch (InvalidCalendarChange) {
            throw new InvalidCalendarChange(self::NOT_A_LAYOUT);
        }

        $sourceId = $file->sourceCalendar ?? null;

        return [$layout, is_string($sourceId) ? $this->sibling($calendar, $sourceId) : null];
    }

    /** Another calendar of the same project, or null (missing, foreign, deleted, not an id). */
    private function sibling(Calendar $calendar, string $id): null|Calendar
    {
        if (preg_match(CalendarLayoutAssets::ID_PATTERN, $id) !== 1) {
            return null;
        }

        try {
            $other = $this->calendarRepository->get(Uuid::fromString($id));
        } catch (CalendarNotFound) {
            return null;
        }

        return $other->project->id->equals($calendar->project->id) ? $other : null;
    }
}
