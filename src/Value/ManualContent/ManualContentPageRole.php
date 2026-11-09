<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * What a {@see \WBoost\Web\Entity\ManualContentPage} is in its manual. The
 * intro is pinned first and exists at most once per manual (partial unique
 * index); custom pages (P2) share the sortable zone with the mockup pages.
 */
enum ManualContentPageRole: string
{
    case Intro = 'intro';
    case Custom = 'custom';
}
