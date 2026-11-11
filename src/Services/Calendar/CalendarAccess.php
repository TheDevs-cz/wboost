<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Security\CalendarVoter;
use WBoost\Web\Value\Calendar\CalendarActor;

/**
 * Resolves the role a calendar request acts in
 * (docs/calendar/architecture.md §A "Roles"): the designer is whoever may
 * edit the project; a member with only VIEW and the holder of the client
 * link are clients.
 */
readonly final class CalendarAccess
{
    public function __construct(
        private Security $security,
        private CalendarRepository $calendarRepository,
    ) {
    }

    /** The logged-in user's role for this calendar (the route already guaranteed VIEW). */
    public function forUser(Calendar $calendar): CalendarActor
    {
        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->id : null;

        return $this->security->isGranted(CalendarVoter::EDIT, $calendar)
            ? CalendarActor::designer($userId)
            : CalendarActor::client($userId);
    }

    /**
     * The holder of a client link is always a client — whoever they are.
     *
     * @return array{Calendar, CalendarActor}
     *
     * @throws CalendarNotFound unknown (or regenerated) token → 404
     */
    public function forToken(string $token): array
    {
        return [$this->calendarRepository->getByClientToken($token), CalendarActor::client()];
    }
}
