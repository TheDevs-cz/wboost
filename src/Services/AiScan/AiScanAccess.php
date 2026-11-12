<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * The two permissions of AI scan (architecture.md §6), in one place:
 *
 * - **start** — an admin, or a project editor of an entitled project (an admin
 *   only while `admin_only` is set) — and the operating mode is `on`;
 * - **use** — an admin, the requester, or any editor of the scan's project.
 *   Independent of the entitlement, of `admin_only` and of the mode, so
 *   switching any of them never strands a proposal.
 *
 * {@see \WBoost\Web\Services\Security\AiScanVoter} is its `isGranted()` face.
 */
final readonly class AiScanAccess
{
    public function __construct(
        private Security $security,
        private AiScanRuntimeState $runtime,
    ) {
    }

    /** Start without the mode: who is ENTITLED to scan in this project. */
    public function isEntitled(User $user, Project $project): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (!$project->aiScanEnabled || $this->runtime->get()->adminOnly) {
            return false;
        }

        return $this->security->isGrantedForUser($user, ProjectVoter::EDIT, $project);
    }

    public function canStart(User $user, Project $project): bool
    {
        return $this->runtime->get()->mode === AiScanMode::On && $this->isEntitled($user, $project);
    }

    public function canUse(User $user, AiScan $scan): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($scan->requestedBy !== null && $scan->requestedBy->id->equals($user->id)) {
            return true;
        }

        return $this->security->isGrantedForUser($user, ProjectVoter::EDIT, $scan->project);
    }

    private function isAdmin(User $user): bool
    {
        return in_array(User::ROLE_ADMIN, $user->getRoles(), true);
    }
}
