<?php

declare(strict_types=1);

namespace WBoost\Web\Message\EmailSignature;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteEmailSignatureTemplate
{
    public function __construct(
        public UuidInterface $emailSignatureTemplateId,
    ) {
    }
}
