<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Calendar;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Controller\AiScan\AiScanResponses;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\AiScan\AcceptCalendarScan;
use WBoost\Web\Message\AiScan\RestoreCalendarScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\AiScan\Calendar\CalendarScanPictures;
use WBoost\Web\Services\AiScan\Calendar\CalendarScanProposalView;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\AiScanVoter;
use WBoost\Web\Services\Security\CalendarVoter;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * What a designer does with a calendar scan's proposal (ux.md §6) — the
 * three writes that touch the CALENDAR. They live under `/calendar/{id}/…`,
 * need `calendar_edit` (the designer) and exist on no `/k/{token}` address:
 * the client link can neither apply a scanned layout nor take one back.
 *
 * One route, `calendar_scan`: `POST /calendar/{calendarId}/scan/{scanId}/{action}`.
 *
 * | `action` | Does |
 * |---|---|
 * | `accept` (`features[]`) | „Použít rozvržení“ — {@see AcceptCalendarScan} |
 * | `restore` (`confirm`) | „Vrátit původní rozvržení“ — {@see RestoreCalendarScan}; `layout_changed` (409) without `confirm` after later edits |
 * | `picture` (`slot`, `picture` \| `remove=1`) | holds / drops a picture for a place of the proposal |
 *
 * A scan that is not this calendar's is a 404 through any of them. The
 * CSRF check is the scan endpoints' stateless one (`_token=csrf-token`).
 * Accept and restore answer the saved calendar (`document`, the shape of a
 * save answer) so the workspace reloads from it.
 */
final class CalendarScanController extends AbstractController
{
    use AiScanResponses;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private AiScanRepository $repository,
        readonly private AiScans $scans,
        readonly private CalendarDocument $document,
        readonly private CalendarScanProposalView $proposalView,
        readonly private CalendarScanPictures $pictures,
        readonly private ReleaseSessionLock $releaseSessionLock,
    ) {
    }

    #[Route(
        path: '/calendar/{calendarId}/scan/{scanId}/{action}',
        name: 'calendar_scan',
        requirements: ['calendarId' => Calendar::ID_REQUIREMENT, 'scanId' => Calendar::ID_REQUIREMENT, 'action' => 'accept|restore|picture'],
        methods: ['POST'],
    )]
    #[IsGranted(CalendarVoter::EDIT, 'calendar')]
    public function __invoke(
        #[MapEntity(id: 'calendarId')]
        Calendar $calendar,
        string $scanId,
        string $action,
        Request $request,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);
        $this->assertCsrf($request);

        $scan = $this->repository->find(Uuid::fromString($scanId));

        // Somebody else's scan, or a scan of another calendar: not here.
        if ($scan === null || $scan->calendar === null || !$scan->calendar->id->equals($calendar->id) || !$this->isGranted(AiScanVoter::USE, $scan)) {
            throw $this->createNotFoundException();
        }

        $payload = $this->payload($request);
        $this->releaseSessionLock->release($request);

        if ($action === 'picture') {
            return $this->picture($scan, $request);
        }

        $features = is_array($payload['features'] ?? null) ? array_values(array_filter($payload['features'], is_string(...))) : [];

        $result = $this->attempt($this->bus, $action === 'accept'
            ? new AcceptCalendarScan($scan->id, $user->id, $features)
            : new RestoreCalendarScan($scan->id, $user->id, in_array($payload['confirm'] ?? null, [true, 1, '1', 'true'], true)));

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $scan = $this->repository->get($scan->id);

        return new JsonResponse(['ok' => true] + (is_array($result) ? $result : []) + [
            'document' => $this->document->saved($calendar),
            'scan' => $this->scans->view($scan),
            'applied' => $this->proposalView->applied($scan),
        ]);
    }

    private function picture(AiScan $scan, Request $request): Response
    {
        $slot = $request->request->getString('slot');
        $file = $request->files->get('picture');

        try {
            if ($request->request->getBoolean('remove')) {
                $this->pictures->remove($scan, $slot);
            } elseif ($file instanceof UploadedFile) {
                $this->pictures->hold($scan, $slot, $file);
            } else {
                return new JsonResponse(['ok' => false, 'code' => 'invalid_fix', 'field' => 'picture'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (AiScanRefused $refused) {
            return $this->refusal($refused->refusal, $refused->params);
        }

        return new JsonResponse(['ok' => true, 'proposal' => $this->proposalView->view($this->repository->get($scan->id))]);
    }
}
