<?php

declare(strict_types=1);

namespace WBoost\Web\Message\EmailSignature;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\EmailTextInput;

readonly final class SaveEmailSignatureTemplateEditor
{
    public function __construct(
        public UuidInterface $templateId,
        public string $code,
        /** @var array<EmailTextInput> */
        public array $textInputs,
    ) {
    }
}
