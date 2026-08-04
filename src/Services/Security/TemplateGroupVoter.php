<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\User;

/**
 * Designing a group (EDIT) is a designer tool: admins pass outright, everyone
 * else must hold ROLE_DESIGNER *and* own the project. Filling & exporting a
 * group (VIEW) is open to anyone who can view the group's project — the same
 * semantics {@see ProjectVoter::VIEW} gives (admin, owner, any shared user).
 *
 * @extends Voter<string, TemplateGroup>
 */
final class TemplateGroupVoter extends Voter
{
    public const string VIEW = 'template_group_view';
    public const string EDIT = 'template_group_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT], true)) {
            return false;
        }

        return $subject instanceof TemplateGroup;
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

        if ($attribute === self::VIEW) {
            return $this->security->isGranted(ProjectVoter::VIEW, $subject->project);
        }

        if (!$this->security->isGranted(User::ROLE_DESIGNER)) {
            return false;
        }

        if ($subject->project->owner === $user) {
            return true;
        }

        return false;
    }
}
