<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One template that references a gallery image — in a variant's canvas
 * document (a decorative picture, a placeholder's designed stand-in, a list
 * bullet), as a variant's background or in its inputs. One site per
 * template: the Koš names the template, the purge guard counts it.
 */
readonly final class GalleryImageUsageSite
{
    public function __construct(
        public string $templateId,
        public string $templateName,
        public string $variantId,
    ) {
    }
}
