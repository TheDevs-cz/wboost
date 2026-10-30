<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\User;

/**
 * The template-group semantics ({@see TemplateGroupVoter}): arranging a
 * document (EDIT — pages, shared fields, print setup, rename, delete) is a
 * designer tool for the project owner; filling & exporting it (VIEW) is open
 * to anyone who can view the project.
 *
 * @extends Voter<string, TemplateDocument>
 */
final class TemplateDocumentVoter extends Voter
{
    public const string VIEW = 'template_document_view';
    public const string EDIT = 'template_document_edit';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT], true)) {
            return false;
        }

        return $subject instanceof TemplateDocument;
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

        return $subject->project->owner === $user;
    }
}
