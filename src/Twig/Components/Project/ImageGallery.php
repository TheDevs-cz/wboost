<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components\Project;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

/**
 * Stage 7: project-scoped image gallery for the admin editor.
 *
 * Lists every FileUpload for the project + source so admins can reuse a
 * previously uploaded asset across templates instead of re-uploading.
 *
 * The component itself stays read-only — uploads still go through the
 * existing `project_upload_file` route, driven by the small
 * `gallery-uploader` Stimulus controller in the modal's "upload" tab. After
 * a successful upload the host page emits a `live#emit` action so this
 * component re-renders and the freshly uploaded asset shows up in the grid.
 *
 * Asset selection is a pure DOM event (CustomEvent("asset-selected") on the
 * modal root) so the host editor's Stimulus controller can route the URL to
 * either `setBackgroundImage` or `addImageToCanvas` depending on the mode it
 * opened the modal in. No round-trip needed for the selection itself.
 */
#[AsLiveComponent('Project:ImageGallery')]
#[IsGranted(ProjectVoter::EDIT, 'project')]
final class ImageGallery extends AbstractController
{
    use DefaultActionTrait;

    /**
     * The project whose uploads are listed. Live Components hydrate Doctrine
     * entities by id, so this property round-trips as the project's UUID.
     *
     * Declared nullable to satisfy PHPStan's uninitialized-property check —
     * Live Components hydrate the property after construction, so a non-null
     * default is not possible at the language level. In practice it is always
     * set when the component renders or an action fires (see assert()s below).
     */
    #[LiveProp]
    public null|Project $project = null;

    /**
     * Filters which uploads to show. Defaults to social network images, the
     * only source today; the prop exists so other features can mount the same
     * component for their own asset directory once a new FileSource lands.
     */
    #[LiveProp]
    public FileSource $source = FileSource::SocialNetworkImage;

    public function __construct(
        private readonly FileUploadRepository $fileUploadRepository,
        private readonly UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * Each entry carries both the public URL (for Fabric / <img>) and the
     * raw storage path (for `EditSocialNetworkTemplateVariant`-style
     * persistence flows that store the path, not the full URL).
     *
     * @return list<array{id: string, url: string, path: string, uploadedAt: string}>
     */
    public function assets(): array
    {
        assert($this->project !== null);

        return array_map(
            fn (FileUpload $f): array => [
                'id' => $f->id->toString(),
                'url' => $this->uploaderHelper->getPublicPath($f->path),
                'path' => $f->path,
                'uploadedAt' => $f->uploadedAt->format('Y-m-d H:i'),
            ],
            $this->fileUploadRepository->listByProjectAndSource($this->project->id, $this->source),
        );
    }

    /**
     * Re-render hook called after a successful upload so the freshly added
     * FileUpload shows up in the grid without a full page reload. The action
     * itself is a no-op — the component re-renders by default on every
     * action invocation, which is exactly what we want.
     */
    #[LiveAction]
    public function refresh(): void
    {
    }

    /**
     * URL the upload tab posts to. Reuses the existing per-project upload
     * endpoint — no backend changes needed; FileUpload rows it creates will
     * appear in the grid on the next render.
     */
    public function uploadUrl(): string
    {
        assert($this->project !== null);

        return $this->generateUrl('project_upload_file', [
            'projectId' => $this->project->id->toString(),
            'source' => $this->source->value,
        ]);
    }
}
