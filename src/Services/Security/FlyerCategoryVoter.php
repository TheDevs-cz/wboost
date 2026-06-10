<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\FlyerCategory;
use WBoost\Web\Entity\User;

/**
 * @extends Voter<string, FlyerCategory>
 */
final class FlyerCategoryVoter extends Voter
{
    public const string ADD = 'flyer_category_add';
    public const string EDIT = 'flyer_category_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::ADD, self::EDIT])) {
            return false;
        }

        return $subject instanceof FlyerCategory;
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

        return false;
    }
}
