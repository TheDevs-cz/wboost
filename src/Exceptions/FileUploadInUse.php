<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Value\GalleryImageManualSite;
use WBoost\Web\Value\GalleryImageUsageSite;

/**
 * Raised when a gallery image is about to be PURGED (row + storage object,
 * irreversible) while a template still references it. A purged picture is
 * gone for good — the editor loads a red stand-in, the export renders
 * nothing there — so the nightly purge never removes such a file (it stays
 * in the Koš, which says why) and only the bin's explicit "Smazat ihned",
 * which names the templates in its confirm, may force it.
 *
 * A picture a MANUAL shows ({@see $manualSites}, content pages P3) is
 * refused even when forced: the manual is public and has no stand-in for a
 * vanished object, so it has to be taken out of the manual first.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class FileUploadInUse extends \Exception
{
    /**
     * @param list<GalleryImageUsageSite> $sites
     * @param list<GalleryImageManualSite> $manualSites
     */
    public function __construct(
        public readonly FileUpload $upload,
        public readonly array $sites,
        public readonly array $manualSites = [],
    ) {
        parent::__construct(sprintf('Gallery image %s is still used by %s', $upload->path, $this->usedByLabel()));
    }

    /** "template(s): A, B; manual(s): C" */
    public function usedByLabel(): string
    {
        return implode('; ', array_filter([
            $this->templateNames() !== [] ? 'template(s): ' . implode(', ', $this->templateNames()) : null,
            $this->manualNames() !== [] ? 'manual(s): ' . implode(', ', $this->manualNames()) : null,
        ]));
    }

    /**
     * @return list<string>
     */
    public function manualNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (GalleryImageManualSite $site): string => $site->manualName,
            $this->manualSites,
        )));
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
