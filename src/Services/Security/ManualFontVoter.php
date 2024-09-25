<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Entity\User;

/**
 * @extends Voter<string, ManualFont>
 */
final class ManualFontVoter extends Voter
{
    public const string VIEW = 'manual_font_view';
    public const string EDIT = 'manual_font_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT])) {
            return false;
        }

        return $subject instanceof ManualFont;
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

        return $subject->manual->project->owner === $user;
    }
}
