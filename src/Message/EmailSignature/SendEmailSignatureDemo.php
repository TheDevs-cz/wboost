<?php

declare(strict_types=1);

namespace WBoost\Web\Message\EmailSignature;

use Ramsey\Uuid\UuidInterface;

readonly final class SendEmailSignatureDemo
{
    /**
     * @param non-empty-list<string> $emails
     */
    public function __construct(
        public UuidInterface $templateId,
        public null|UuidInterface $variantId,
        public array $emails,
    ) {
    }
}
