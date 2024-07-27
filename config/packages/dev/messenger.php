<?php declare(strict_types=1);

use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework): void {
    $messenger = $framework->messenger();

    $messenger->routing(SendEmailMessage::class)->senders(['sync']);
};
