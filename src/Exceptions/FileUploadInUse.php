<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Value\GalleryImageUsageSite;

/**
 * Raised when a gallery image is about to be PURGED (row + storage object,
 * irreversible) while a template still references it. A purged picture is
 * gone for good — the editor loads a red stand-in, the export renders
 * nothing there — so the nightly purge never removes such a file (it stays
 * in the Koš, which says why) and only the bin's explicit "Smazat ihned",
 * which names the templates in its confirm, may force it.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class FileUploadInUse extends \Exception
{
    /**
     * @param list<GalleryImageUsageSite> $sites
     */
    public function __construct(
        public readonly FileUpload $upload,
        public readonly array $sites,
    ) {
        parent::__construct(sprintf(
            'Gallery image %s is still used by template(s): %s',
            $upload->path,
            implode(', ', $this->templateNames()),
        ));
    }

    /**
     * @return list<string>
     */
    public function templateNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (GalleryImageUsageSite $site): string => $site->templateName,
            $this->sites,
        )));
    }
}
