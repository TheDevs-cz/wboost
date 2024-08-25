<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;

/**
 * @extends Voter<string, Project>
 */
final class ProjectVoter extends Voter
{
    public const string VIEW = 'project_view';
    public const string EDIT = 'project_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT])) {
            return false;
        }

        return $subject instanceof Project;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted(User::ROLE_ADMIN)) {
            return true;
        }

        if ($subject->owner === $user) {
            return true;
        }

        $sharingLevel = $subject->getUserSharingLevel($user);

        // Project not shared at all
        if ($sharingLevel === null) {
            return false;
        }

        // Project is shared, view is the least and any sharing allows viewing
        if ($attribute === self::VIEW) {
            return true;
        }

        return false;
    }
}
