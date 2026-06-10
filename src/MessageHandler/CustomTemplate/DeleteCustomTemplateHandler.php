<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CustomTemplateNotFound;
use WBoost\Web\Message\CustomTemplate\DeleteCustomTemplate;
use WBoost\Web\Repository\CustomTemplateRepository;

#[AsMessageHandler]
readonly final class DeleteCustomTemplateHandler
{
    public function __construct(
        private CustomTemplateRepository $templateRepository,
    ) {
    }

    /**
     * @throws CustomTemplateNotFound
     */
    public function __invoke(DeleteCustomTemplate $message): void
    {
        $template = $this->templateRepository->get($message->templateId);

        $this->templateRepository->remove($template);
    }
}
