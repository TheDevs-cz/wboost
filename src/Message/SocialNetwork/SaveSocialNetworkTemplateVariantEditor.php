<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\EditorTextInput;

readonly final class SaveSocialNetworkTemplateVariantEditor
{
    public function __construct(
        public UuidInterface $variantId,
        public string $canvas,
        /** @var array<EditorTextInput> */
        public array $inputs,
    ) {
    }
}
