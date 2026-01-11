<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\SocialNetworkCategory;
use WBoost\Web\Entity\User;

/**
 * @extends Voter<string, SocialNetworkCategory>
 */
final class SocialNetworkCategoryVoter extends Voter
{
    public const string ADD = 'social_category_add';
    public const string EDIT = 'social_category_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::ADD, self::EDIT])) {
            return false;
        }

        return $subject instanceof SocialNetworkCategory;
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

        // Project not shared at all
        if ($sharingLevel === null) {
            return false;
        }

        return false;
    }
}
