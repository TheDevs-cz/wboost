<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\User;

/**
 * Rollout switch for multi-page documents. Every push deploys to prod, and the
 * feature lands in phases (UI, fill, export, API/MCP), so until it is complete
 * it is visible to admins only. It gates the entry points AND the hiding of
 * page templates from the listing: with the switch off a page is just an
 * ordinary template again — an admin trying documents on a client project must
 * not make that project's templates vanish for its users.
 */
readonly final class TemplateDocumentsFeature
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->security->isGranted(User::ROLE_ADMIN);
    }
}
