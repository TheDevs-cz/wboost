<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final readonly class SocialNetworkTemplateVariantInputResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|int $maxLength,
        public bool $locked,
        public bool $uppercase,
        public null|string $description,
        public bool $hidable,
        public null|SocialNetworkTemplateVariantInputFrameResponse $frame,
        /**
         * Id of the container this input belongs to (see the variant's
         * `containers`), or null for an independent input. Members reflow at
         * render time, so `frame` is the DESIGNED position — the effective
         * position depends on the fill.
         */
        public null|string $containerId = null,
        public null|SocialNetworkTemplateVariantInputTextStyleResponse $textStyle = null,
    ) {
    }
}
