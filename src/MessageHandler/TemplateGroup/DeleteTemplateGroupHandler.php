<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateGroup;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateGroupNotFound;
use WBoost\Web\Message\TemplateGroup\DeleteTemplateGroup;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateGroupRepository;

#[AsMessageHandler]
readonly final class DeleteTemplateGroupHandler
{
    public function __construct(
        private TemplateGroupRepository $templateGroupRepository,
        private GetTemplateGroupMembers $members,
        private TemplateRepository $templateRepository,
    ) {
    }

    /**
     * @throws TemplateGroupNotFound
     */
    public function __invoke(DeleteTemplateGroup $message): void
    {
        $group = $this->templateGroupRepository->get($message->groupId);

        if ($message->deleteTemplates) {
            // Template removal cascades to ALL its variants at the DB level,
            // including variants a user added to the grouped template manually.
            $template = $this->members->template($group->id);

            if ($template !== null) {
                $this->templateRepository->remove($template);
            }
        }

        // Without template deletion this only un-groups: every group FK is
        // ON DELETE SET NULL, so member templates/variants become ordinary ones.
        $this->templateGroupRepository->remove($group);
    }
}
