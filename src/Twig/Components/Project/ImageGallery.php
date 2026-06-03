<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components\Project;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\CreateFileDirectory;
use WBoost\Web\Message\Image\DeleteFileDirectory;
use WBoost\Web\Message\Image\MoveFileUpload;
use WBoost\Web\Message\Image\RenameFileDirectory;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

/**
 * Stage 8: project-scoped, filesystem-like image gallery for the admin editor.
 *
 * Lists the FileUploads + folders for the project + source so admins can reuse
 * and organize previously uploaded assets across templates. Folders are nested
 * {@see FileDirectory} rows; the component tracks the currently-open folder in
 * `$currentDirectoryId` and exposes navigation + folder-CRUD as LiveActions.
 *
 * Uploads still go through the existing `project_upload_file` route, driven by
 * the `gallery-uploader` Stimulus controller in the modal's "upload" tab; the
 * currently-open folder rides along as a hidden `directoryId` field so new
 * files land where the user is looking. After a successful upload the
 * `image-gallery` controller clicks the hidden `refresh` trigger so this
 * component re-renders and the new asset shows up.
 *
 * Asset selection stays a pure DOM event (CustomEvent("asset-selected") on the
 * modal root) so the host editor's Stimulus controller can route the URL to
 * either `setBackgroundImage` or `addImageToCanvas`.
 *
 * Authorisation note: `#[IsGranted]` cannot be applied at class level — the
 * Symfony Security listener resolves the subject from controller-method
 * arguments, and a Live Component's `$project` is a hydrated LiveProp, not an
 * argument; on a LiveAction request that fails with "Could not find the subject
 * 'project'". Access is enforced explicitly in `postMount()` and via `guard()`
 * at the top of every render method and LiveAction. Folder/file ids arriving
 * from the client are always re-checked against this project + source via
 * `owns()` before they are trusted. Mirrors SocialNetwork:VariantFiller.
 */
#[AsLiveComponent('Project:ImageGallery')]
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
     * set when the component renders or an action fires (see guard()).
     */
    #[LiveProp]
    public null|Project $project = null;

    /**
     * Filters which uploads/folders to show. Defaults to social network images,
     * the only source today; the prop exists so other features can mount the
     * same component for their own asset tree once a new FileSource lands.
     */
    #[LiveProp]
    public FileSource $source = FileSource::SocialNetworkImage;

    /**
     * Whether the component is hosted inside the editor's Bootstrap modal
     * (default) or rendered standalone on the gallery management page. In modal
     * mode it shows the modal header/close chrome and each image is a
     * click-to-select button (the editor routes the pick onto the canvas);
     * standalone it drops that chrome and renders plain thumbnails — folders,
     * upload and move are the management surface.
     */
    #[LiveProp]
    public bool $modal = true;

    /**
     * UUID of the folder currently being viewed, or null for the root. Set
     * server-side by the navigation actions only (NOT writable), so a tampered
     * value can never be written directly from the client; even so, every read
     * re-validates ownership via {@see owns()}.
     */
    #[LiveProp]
    public null|string $currentDirectoryId = null;

    /** Bound to the "new folder" input via data-model. */
    #[LiveProp(writable: true)]
    public string $newDirectoryName = '';

    /** UUID of the folder being renamed inline, or null when not renaming. */
    #[LiveProp]
    public null|string $renamingDirectoryId = null;

    /** Bound to the inline rename input via data-model. */
    #[LiveProp(writable: true)]
    public string $renameValue = '';

    public function __construct(
        private readonly FileUploadRepository $fileUploadRepository,
        private readonly FileDirectoryRepository $fileDirectoryRepository,
        private readonly UploaderHelper $uploaderHelper,
        private readonly MessageBusInterface $bus,
        private readonly ProvideIdentity $provideIdentity,
    ) {
    }

    #[PostMount]
    public function postMount(): void
    {
        $this->guard();
    }

    /**
     * Files that live directly inside the currently-open folder. Each entry
     * carries both the public URL (for Fabric / <img>) and the raw storage
     * path (for background-persistence flows that store the path, not the URL).
     *
     * @return list<array{id: string, url: string, path: string, uploadedAt: string}>
     */
    public function assets(): array
    {
        $project = $this->guard();

        return array_map(
            fn (FileUpload $f): array => [
                'id' => $f->id->toString(),
                'url' => $this->uploaderHelper->getPublicPath($f->path),
                'path' => $f->path,
                'uploadedAt' => $f->uploadedAt->format('Y-m-d H:i'),
            ],
            $this->fileUploadRepository->listByProjectSourceAndDirectory($project->id, $this->source, $this->currentDirectory()),
        );
    }

    /**
     * Sub-folders of the currently-open folder.
     *
     * @return list<array{id: string, name: string}>
     */
    public function directories(): array
    {
        $project = $this->guard();

        return array_map(
            fn (FileDirectory $d): array => [
                'id' => $d->id->toString(),
                'name' => $d->name,
            ],
            $this->fileDirectoryRepository->listChildren($project->id, $this->source, $this->currentDirectory()),
        );
    }

    /**
     * The folder chain from the root down to (and including) the open folder,
     * for breadcrumb rendering. Empty at the root.
     *
     * @return list<array{id: string, name: string}>
     */
    public function breadcrumbs(): array
    {
        $chain = [];
        $directory = $this->currentDirectory();

        while ($directory !== null) {
            $chain[] = ['id' => $directory->id->toString(), 'name' => $directory->name];
            $directory = $directory->parent;
        }

        return array_reverse($chain);
    }

    /**
     * Every folder in the project + source as a flat, indented list (root
     * first) for the per-image "move to folder" picker.
     *
     * @return list<array{id: null|string, label: string}>
     */
    public function moveTargets(): array
    {
        $project = $this->guard();

        /** @var array<string, list<FileDirectory>> $childrenByParent */
        $childrenByParent = [];
        foreach ($this->fileDirectoryRepository->listAll($project->id, $this->source) as $directory) {
            $parentKey = $directory->parent?->id->toString() ?? '';
            $childrenByParent[$parentKey][] = $directory;
        }

        /** @var list<array{id: null|string, label: string}> $result */
        $result = [['id' => null, 'label' => 'Kořenová složka']];
        $this->appendMoveTargets($childrenByParent, '', 0, $result);

        return $result;
    }

    public function uploadUrl(): string
    {
        $project = $this->guard();

        return $this->generateUrl('project_upload_file', [
            'projectId' => $project->id->toString(),
            'source' => $this->source->value,
        ]);
    }

    /**
     * Re-render hook the uploader clicks after a successful upload so the new
     * asset shows up. Re-render happens by default on every action.
     */
    #[LiveAction]
    public function refresh(): void
    {
        $this->guard();
    }

    #[LiveAction]
    public function openDirectory(#[LiveArg('directoryid')] string $directoryId): void
    {
        $this->guard();
        $this->cancelRenameState();

        $directory = $this->ownedDirectory($directoryId);
        if ($directory !== null) {
            $this->currentDirectoryId = $directory->id->toString();
        }
    }

    #[LiveAction]
    public function openRoot(): void
    {
        $this->guard();
        $this->cancelRenameState();
        $this->currentDirectoryId = null;
    }

    #[LiveAction]
    public function createDirectory(): void
    {
        $project = $this->guard();

        $name = trim($this->newDirectoryName);
        if ($name !== '') {
            $parent = $this->currentDirectory();

            $this->bus->dispatch(new CreateFileDirectory(
                $this->provideIdentity->next(),
                $project->id,
                $this->source,
                $parent?->id,
                $name,
            ));
        }

        $this->newDirectoryName = '';
    }

    #[LiveAction]
    public function startRename(#[LiveArg('directoryid')] string $directoryId): void
    {
        $this->guard();

        $directory = $this->ownedDirectory($directoryId);
        if ($directory !== null) {
            $this->renamingDirectoryId = $directory->id->toString();
            $this->renameValue = $directory->name;
        }
    }

    #[LiveAction]
    public function cancelRename(): void
    {
        $this->guard();
        $this->cancelRenameState();
    }

    #[LiveAction]
    public function renameDirectory(): void
    {
        $this->guard();

        $name = trim($this->renameValue);
        $directory = $this->renamingDirectoryId !== null ? $this->ownedDirectory($this->renamingDirectoryId) : null;

        if ($directory !== null && $name !== '') {
            $this->bus->dispatch(new RenameFileDirectory($directory->id, $name));
        }

        $this->cancelRenameState();
    }

    #[LiveAction]
    public function deleteDirectory(#[LiveArg('directoryid')] string $directoryId): void
    {
        $this->guard();

        $directory = $this->ownedDirectory($directoryId);
        if ($directory === null) {
            return;
        }

        // If we are standing inside the folder being deleted, step up to its
        // parent so the view doesn't point at a folder that no longer exists.
        if ($this->currentDirectoryId === $directory->id->toString()) {
            $this->currentDirectoryId = $directory->parent?->id->toString();
        }

        $this->bus->dispatch(new DeleteFileDirectory($directory->id));
        $this->cancelRenameState();
    }

    #[LiveAction]
    public function moveFile(#[LiveArg('fileid')] string $fileId, #[LiveArg('directoryid')] string $directoryId): void
    {
        $project = $this->guard();

        if (!Uuid::isValid($fileId)) {
            return;
        }

        try {
            $file = $this->fileUploadRepository->get(Uuid::fromString($fileId));
        } catch (FileUploadNotFound) {
            return;
        }

        if (!$file->project->id->equals($project->id)) {
            return;
        }

        $target = null;
        if ($directoryId !== '') {
            $target = $this->ownedDirectory($directoryId);
            if ($target === null) {
                // A non-empty but unrecognised target: refuse rather than
                // silently moving the file to the root.
                return;
            }
        }

        $this->bus->dispatch(new MoveFileUpload($file->id, $target?->id));
    }

    /**
     * Assert the project is set and the user may edit it; returns the
     * non-null Project so callers keep PHPStan's narrowing across the
     * intervening denyAccess() call.
     */
    private function guard(): Project
    {
        $project = $this->project;
        assert($project !== null);

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        return $project;
    }

    private function currentDirectory(): null|FileDirectory
    {
        return $this->currentDirectoryId !== null ? $this->ownedDirectory($this->currentDirectoryId) : null;
    }

    /**
     * Resolve a client-supplied id to a directory ONLY if it belongs to this
     * project + source; otherwise null. The single choke point that keeps an
     * EDIT grant on one project from touching another project's folders.
     */
    private function ownedDirectory(string $directoryId): null|FileDirectory
    {
        if (!Uuid::isValid($directoryId)) {
            return null;
        }

        try {
            $directory = $this->fileDirectoryRepository->get(Uuid::fromString($directoryId));
        } catch (FileDirectoryNotFound) {
            return null;
        }

        $project = $this->project;
        assert($project !== null);

        // Source isolation is enforced by the single FileSource case today; when
        // FileSource gains more cases, also guard `$directory->source === $this->source`.
        if (!$directory->project->id->equals($project->id)) {
            return null;
        }

        return $directory;
    }

    private function cancelRenameState(): void
    {
        $this->renamingDirectoryId = null;
        $this->renameValue = '';
    }

    /**
     * @param array<string, list<FileDirectory>> $childrenByParent
     * @param list<array{id: null|string, label: string}> $result
     */
    private function appendMoveTargets(array $childrenByParent, string $parentKey, int $depth, array &$result): void
    {
        foreach ($childrenByParent[$parentKey] ?? [] as $directory) {
            $result[] = [
                'id' => $directory->id->toString(),
                'label' => str_repeat('— ', $depth) . $directory->name,
            ];
            $this->appendMoveTargets($childrenByParent, $directory->id->toString(), $depth + 1, $result);
        }
    }
}
