<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\ManualContentPage;

/**
 * A content page is edited (and renamed / deleted / sorted) by whoever may
 * edit its manual — `ManualVoter::EDIT` (admin or project owner; a shared
 * project never grants edit).
 *
 * @extends Voter<string, ManualContentPage>
 */
final class ManualContentPageVoter extends Voter
{
    public const string EDIT = 'manual_content_page_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT && $subject instanceof ManualContentPage;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->security->isGranted(ManualVoter::EDIT, $subject->manual);
    }
}
