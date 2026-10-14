<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Landing;

use WBoost\Web\Value\LandingLeadSource;

readonly final class CaptureLandingLead
{
    /**
     * @param list<string> $interests
     */
    public function __construct(
        public LandingLeadSource $source,
        public string $email,
        public null|string $name = null,
        public null|string $phone = null,
        public null|string $company = null,
        public null|string $website = null,
        public array $interests = [],
        public null|bool $hasDesigner = null,
        public null|string $designerName = null,
        public null|string $designerEmail = null,
        public null|string $message = null,
    ) {
    }
}
