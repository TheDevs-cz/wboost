<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\TemplateExportVersion;

/**
 * Curating the export history (naming / pinning a version) is open to
 * everyone who can FILL the version's surface: the history is one shared list
 * per template and the people returning to a version are the end users, so it
 * delegates to the fill page's own VIEW check — the variant voter for a
 * single-variant version, the group voter for a group version.
 *
 * @extends Voter<string, TemplateExportVersion>
 */
final class TemplateExportVersionVoter extends Voter
{
    public const string MANAGE = 'template_export_version_manage';

    public function __construct(
        readonly private Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE && $subject instanceof TemplateExportVersion;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($subject->variant !== null) {
            return $this->security->isGranted(TemplateVariantVoter::VIEW, $subject->variant);
        }

        if ($subject->group !== null) {
            return $this->security->isGranted(TemplateGroupVoter::VIEW, $subject->group);
        }

        return false;
    }
}
