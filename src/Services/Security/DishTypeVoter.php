<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\DishType;
use WBoost\Web\Entity\User;

/**
 * @extends Voter<string, DishType>
 */
final class DishTypeVoter extends Voter
{
    public const string VIEW = 'dish_type_view';
    public const string EDIT = 'dish_type_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT])) {
            return false;
        }

        return $subject instanceof DishType;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted(User::ROLE_ADMIN)) {
            return true;
        }

        if ($subject->project->owner === $user) {
            return true;
        }

        $sharingLevel = $subject->project->getUserSharingLevel($user);

        if ($sharingLevel === null) {
            return false;
        }

        if ($attribute === self::VIEW) {
            return true;
        }

        return false;
    }
}
