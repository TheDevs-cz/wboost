<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;

/**
 * Dispatches a document change for a web controller: a refused invariant
 * comes back as the user-facing reason (for a flash message) instead of a
 * 500; anything else still throws.
 */
readonly final class DispatchDocumentChange
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @return null|InvalidTemplateDocumentChange null when the change went through
     */
    public function __invoke(object $message): null|InvalidTemplateDocumentChange
    {
        try {
            $this->bus->dispatch($message);
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if ($real instanceof InvalidTemplateDocumentChange) {
                return $real;
            }

            throw $failed;
        }

        return null;
    }
}
