<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One manual that shows a gallery image — on a content page (intro or
 * custom), in a mockup page slot, or as the legacy `manual.intro_image`.
 * A picture a manual shows is NEVER purged from storage (not even by a
 * forced "Smazat ihned"): the manual is public and has no stand-in for a
 * vanished object. One site per manual.
 */
readonly final class GalleryImageManualSite
{
    public function __construct(
        public string $manualId,
        public string $manualName,
    ) {
    }
}
