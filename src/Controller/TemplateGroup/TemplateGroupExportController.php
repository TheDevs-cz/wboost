<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\Slugify;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;
use ZipArchive;

/**
 * Export the WHOLE group at once: every member variant is rendered with the
 * unified fill values ({@see GroupFillRenderer}) and the PNGs come back as
 * one ZIP download.
 *
 * The response is fully buffered on purpose — a flushing StreamedResponse
 * corrupts the next request under FrankenPHP's resident process.
 */
final class TemplateGroupExportController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateGroupMembers $members,
        readonly private GroupFillRenderer $groupFillRenderer,
        readonly private RecordExportUsage $recordExportUsage,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/export', name: 'template_group_export', methods: ['POST'])]
    #[IsGranted(TemplateGroupVoter::EDIT, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
        Request $request,
    ): Response {
        /** @var list<SocialNetworkTemplateVariant|CustomTemplateVariant> $variants */
        $variants = [...$this->members->socialVariants($group->id), ...$this->members->customVariants($group->id)];

        if ($variants === []) {
            throw $this->createNotFoundException('The group has no variants to export.');
        }

        $rawTextValues = $request->request->all('textValues');
        $rawHiddenValues = $request->request->all('hiddenValues');
        $rawImages = $request->request->all('images');

        $groupSlug = $this->nonEmptySlug($group->name, 'export');

        /** @var array<string, string> $files filename → PNG bytes */
        $files = [];

        foreach ($variants as $variant) {
            $bytes = $this->groupFillRenderer->renderPng($variant, $rawTextValues, $rawHiddenValues, $rawImages);

            $baseName = sprintf('%s-%s', $groupSlug, $this->dimensionSlug($variant));
            $fileName = "$baseName.png";

            // Two variants may share a dimension (e.g. two identical custom
            // sizes) — suffix instead of silently overwriting a ZIP entry.
            for ($suffix = 2; isset($files[$fileName]); $suffix++) {
                $fileName = "$baseName-$suffix.png";
            }

            $files[$fileName] = $bytes;
        }

        $zipBytes = $this->buildZip($files);

        foreach ($variants as $variant) {
            $this->recordExportUsage->record($variant, ExportChannel::Web);
        }

        return new Response($zipBytes, Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="%s.zip"', $groupSlug),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param array<string, string> $files
     */
    private function buildZip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'group-export-');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the ZIP export.');
        }

        try {
            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the ZIP archive for writing.');
            }

            foreach ($files as $fileName => $bytes) {
                $zip->addFromString($fileName, $bytes);
            }

            $zip->close();

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new \RuntimeException('Could not read the finished ZIP archive.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    private function dimensionSlug(SocialNetworkTemplateVariant|CustomTemplateVariant $variant): string
    {
        if ($variant instanceof SocialNetworkTemplateVariant) {
            return $this->nonEmptySlug(
                sprintf('%s %dx%d', $variant->dimension->value, $variant->dimension->width(), $variant->dimension->height()),
                'varianta',
            );
        }

        return $this->nonEmptySlug($variant->dimension->label(), 'varianta');
    }

    private function nonEmptySlug(string $value, string $fallback): string
    {
        $slug = Slugify::string($value);

        return $slug !== '' ? $slug : $fallback;
    }
}
