<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\EmailSignature\AddEmailSignatureTemplate;
use WBoost\Web\Repository\EmailSignatureTemplateRepository;
use WBoost\Web\Repository\ProjectRepository;

#[AsMessageHandler]
readonly final class AddEmailSignatureTemplateHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private EmailSignatureTemplateRepository $emailSignatureTemplateRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddEmailSignatureTemplate $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $now = $this->clock->now();
        $backgroundImagePath = null;

        if ($message->backgroundImage !== null) {
            $timestamp = $now->getTimestamp();

            $extension = $message->backgroundImage->guessExtension();
            $backgroundImagePath = "emails/$message->emailSignatureTemplateId/background-$timestamp.$extension";

            $fileContent = $message->backgroundImage->getContent();
            $this->filesystem->write($backgroundImagePath, $fileContent);
        }

        $emailSignatureTemplate = new EmailSignatureTemplate(
            id: $message->emailSignatureTemplateId,
            project: $project,
            createdAt: $now,
            name: $message->name,
            code: '',
            backgroundImage: $backgroundImagePath,
        );

        $this->emailSignatureTemplateRepository->add($emailSignatureTemplate);
    }
}
