<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Mail;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Every outgoing HTML e-mail also gets a plain-text part, derived from its HTML,
 * so the dozen senders (InvitationMailer, the *Handler classes,
 * AiScanNotifications…) do not each have to render one.
 *
 * HTML-only mail scores worse with spam filters and is unreadable in text-only
 * clients. A mail whose sender set a text part itself is left alone.
 */
#[AsEventListener(event: MessageEvent::class)]
final readonly class AddPlainTextPartListener
{
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (!$message instanceof Email || $message->getTextBody() !== null) {
            return;
        }

        $html = $message->getHtmlBody();

        if (is_string($html) && $html !== '') {
            $message->text(HtmlToPlainText::convert($html), $message->getHtmlCharset() ?? 'utf-8');
        }
    }
}
