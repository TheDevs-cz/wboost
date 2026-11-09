<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\MockupPageLayout;

/**
 * The whole content page from the editor — an upsert: the page is created
 * when `pageId` does not exist yet (for the intro: when the manual has no
 * intro page, otherwise its existing intro is updated whatever the id).
 */
readonly final class SaveManualContentPage
{
    /**
     * @param list<ContentSegment> $segments one per layout slot
     */
    public function __construct(
        public UuidInterface $manualId,
        public UuidInterface $pageId,
        public ManualContentPageRole $role,
        public string $name,
        public MockupPageLayout $layout,
        public array $segments,
    ) {
    }
}
