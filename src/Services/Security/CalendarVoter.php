<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\User;

/**
 * A calendar is as accessible as its project: admin / owner may edit (the
 * designer), a member the project is shared with may view — and VIEW is what
 * the client role needs (docs/calendar/architecture.md §A "Roles").
 *
 * @extends Voter<string, Calendar>
 */
final class CalendarVoter extends Voter
{
    public const string VIEW = 'calendar_view';
    public const string EDIT = 'calendar_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT], true)) {
            return false;
        }

        return $subject instanceof Calendar;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token->getUser() instanceof User) {
            return false;
        }

        return $this->security->isGranted(
            $attribute === self::EDIT ? ProjectVoter::EDIT : ProjectVoter::VIEW,
            $subject->project,
        );
    }
}
