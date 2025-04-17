<?php

declare(strict_types=1);

namespace WBoost\Web\Message\EmailSignature;

use Ramsey\Uuid\UuidInterface;

readonly final class AddEmailSignatureVariant
{
    public function __construct(
        public UuidInterface $variantId,
        public UuidInterface $templateId,
        public string $name,
    ) {
    }
}
