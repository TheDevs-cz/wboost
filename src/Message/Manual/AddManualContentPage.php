<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\MockupPageLayout;

/**
 * "Přidat stránku → Obsahová stránka": a new, still empty CUSTOM content
 * page at the END of the manual's shared page list. The admin fills it in
 * the editor (an empty page is not shown in the manual).
 */
readonly final class AddManualContentPage
{
    public function __construct(
        public UuidInterface $manualId,
        public UuidInterface $pageId,
        public string $name,
        public MockupPageLayout $layout,
    ) {
    }
}
