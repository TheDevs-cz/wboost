<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\EmailSignature;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\EmailSignatureTemplateNotFound;
use WBoost\Web\Message\EmailSignature\EditEmailSignatureTemplate;
use WBoost\Web\Repository\EmailSignatureTemplateRepository;

#[AsMessageHandler]
readonly final class EditEmailSignatureTemplateHandler
{
    public function __construct(
        private EmailSignatureTemplateRepository $emailSignatureTemplateRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws EmailSignatureTemplateNotFound
     */
    public function __invoke(EditEmailSignatureTemplate $message): void
    {
        $emailTemplate = $this->emailSignatureTemplateRepository->get($message->templateId);
        $backgroundImagePath = $emailTemplate->backgroundImage;

        if ($message->backgroundImage !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $message->backgroundImage->guessExtension();
            $backgroundImagePath = "manuals/$message->templateId/background-$timestamp.$extension";

            $fileContent = $message->backgroundImage->getContent();
            $this->filesystem->write($backgroundImagePath, $fileContent);
        }

        $emailTemplate->edit(
            $message->name,
            $backgroundImagePath,
        );
    }
}
