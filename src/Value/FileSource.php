<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum FileSource: string
{
    case ProjectImage = 'project_image';

    /**
     * The one stand-in picture per project that places left empty by an AI
     * scan reference after accept (docs/architecture/ai-scan.md § "Stand-in").
     * NOT a gallery picture: every gallery listing asks for
     * {@see self::ProjectImage}, so it never shows there, and it can never be
     * moved to the trash ({@see \WBoost\Web\Entity\FileUpload::moveToTrash()})
     * — hence never purged.
     */
    case ScanStandIn = 'scan_stand_in';

    public function isGalleryPicture(): bool
    {
        return $this === self::ProjectImage;
    }
}
