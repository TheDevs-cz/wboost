<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\TemplateDocumentExportVersion;

/**
 * Curating a document's export history (naming / pinning a version) is open
 * to everyone who can FILL the document — the {@see TemplateExportVersionVoter}
 * rule, delegated to the document's VIEW.
 *
 * @extends Voter<string, TemplateDocumentExportVersion>
 */
final class TemplateDocumentExportVersionVoter extends Voter
{
    public const string MANAGE = 'template_document_export_version_manage';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE && $subject instanceof TemplateDocumentExportVersion;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->security->isGranted(TemplateDocumentVoter::VIEW, $subject->document);
    }
}
