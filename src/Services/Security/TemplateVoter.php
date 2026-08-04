<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\User;

/**
 * @extends Voter<string, Template>
 */
final class TemplateVoter extends Voter
{
    public const string ADD = 'template_add';
    public const string VIEW = 'template_view';
    public const string EDIT = 'template_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::ADD, self::VIEW, self::EDIT])) {
            return false;
        }

        return $subject instanceof Template;
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

        // Project is shared, view is the least and any sharing allows viewing
        if ($attribute === self::VIEW) {
            return true;
        }

        return false;
    }
}
