<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\AiScan\AiScanAccess;

/**
 * `ai_scan_start` on a {@see Project}, `ai_scan_use` on an {@see AiScan} —
 * the rules are in {@see AiScanAccess}.
 *
 * @extends Voter<string, Project|AiScan>
 */
final class AiScanVoter extends Voter
{
    public const string START = 'ai_scan_start';
    public const string USE = 'ai_scan_use';

    public function __construct(
        readonly private AiScanAccess $access,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ($attribute === self::START && $subject instanceof Project)
            || ($attribute === self::USE && $subject instanceof AiScan);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($subject instanceof Project) {
            return $this->access->canStart($user, $subject);
        }

        return $this->access->canUse($user, $subject);
    }
}
