<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Which authoring module a tracked export came from. Stored as a string on
 * {@see \WBoost\Web\Entity\ExportEvent} so usage reporting can split numbers
 * per module without joining back to the (possibly deleted) template.
 */
enum ExportedTemplateType: string
{
    case SocialNetwork = 'social_network';
    case CustomTemplate = 'custom_template';

    public function label(): string
    {
        return match ($this) {
            self::SocialNetwork => 'Sociální sítě',
            self::CustomTemplate => 'Šablony',
        };
    }
}
