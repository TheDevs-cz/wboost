<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Mcp\Response\GalleryDirectoryResponse;
use WBoost\Web\Mcp\Response\GalleryImageResponse;
use WBoost\Web\Mcp\Response\ListGalleryResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

/**
 * `list_gallery` — the project's picture library, one folder level at a time.
 *
 * This is where an agent finds the assets it may use: a background, a logo, the
 * photo that fills an image placeholder. The `id` returned here is the SAME
 * identifier the export API's `images` map, the fill surfaces and the design
 * DSL accept, so a picture found here can be referenced without a translation
 * step anywhere.
 *
 * ## Read-only, deliberately
 *
 * There is no delete, move or rename tool, and this one performs none of those
 * as a side effect. Destroying a user's asset is not a mistake an agent gets to
 * make on their behalf; the web gallery (with its trash bin and its undo) is
 * where that happens.
 *
 * ## The trash is invisible here, and the ROOT is where that is load-bearing
 *
 * Deleting a gallery image is a soft delete: it stamps `deletedAt`, DETACHES
 * the file from its folder (remembering `restoreDirectory`) and shows it in the
 * read-only "Koš" view. A detached row has `directory = NULL`, which is exactly
 * the shape of a root file — so a listing that filtered by directory alone
 * would surface the whole bin at the gallery root. Both branches therefore go
 * through {@see FileUploadRepository::listByProjectSourceAndDirectory()}, the
 * same `deletedAt IS NULL` listing the web gallery uses; this class writes no
 * DQL of its own. The bin is likewise not a {@see FileDirectory} row, so it
 * cannot appear among `directories`.
 *
 * ## Authorisation, and why a foreign project is "not found"
 *
 * {@see ProjectVoter::VIEW} — owner, admin, or a user the project is shared
 * with — exactly as `find_templates` and the REST providers apply it. A project
 * the caller may not see reports the SAME failure as an id that matches no row,
 * so the tool cannot be used to enumerate projects; the single wording lives in
 * {@see notFound()}. A client-supplied `directoryId` is re-checked against the
 * resolved project (the {@see \WBoost\Web\Twig\Components\Project\ImageGallery}
 * `ownedDirectory()` guard), and a folder belonging to somebody else is
 * indistinguishable from one that does not exist.
 *
 * ## Pixel sizes are read, not stored
 *
 * Nothing in the database records an image's dimensions, and the aspect ratio is
 * the one property an agent must have to place a picture sensibly. They are
 * therefore read from the files — but only a bounded PREFIX of each
 * ({@see HEADER_BYTES}), since every raster format wboost stores declares its
 * size in a header near the start. A picture whose header does not decode (an
 * SVG, a vanished object) reports null rather than a guess.
 */
#[McpToolScope(McpScope::TemplatesRead)]
readonly final class ListGalleryTool
{
    /**
     * How much of each image is read to recover its pixel size.
     *
     * PNG (IHDR), GIF and WebP declare their dimensions in the first few dozen
     * bytes; JPEG puts its SOF marker after the APPn segments, which an EXIF
     * block plus a split ICC profile can push out by a few hundred KB in the
     * worst case. 256 KiB covers every real file while keeping a listing of a
     * hundred pictures from transferring their full weight.
     */
    private const int HEADER_BYTES = 262144;

    public function __construct(
        private Security $security,
        private ProjectRepository $projectRepository,
        private FileDirectoryRepository $fileDirectoryRepository,
        private FileUploadRepository $fileUploadRepository,
        private UploaderHelper $uploaderHelper,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $filesystem,
    ) {
    }

    /**
     * Lists one level of a project's image gallery: the folders directly inside
     * the current one, and the pictures stored in it. Call get_context first —
     * its projects[].id is the projectId this tool takes.
     *
     * Omit directoryId to list the gallery ROOT. The root is a real place that
     * holds pictures, not merely a container for folders, so look there before
     * concluding a project has no images. To go deeper, pass the id of an entry
     * from directories[]; to go back up, pass parentDirectoryId (null means you
     * are already at the root). path[] is the breadcrumb from the root down to
     * and including the folder being listed, so you can tell the user where you
     * are without retracing your calls.
     *
     * Each image reports the id you reference everywhere else — as a
     * background, as an image-placeholder fill, in an export request — plus its
     * public url and its own pixel size. Use width and height to match a
     * picture to the frame you intend to put it in: a portrait photo dropped
     * into a landscape slot gets cropped, not letterboxed. Both are null for an
     * SVG, which is a vector and scales to whatever box it is placed in, and
     * for a file whose bytes could not be read. name is the stored file name
     * (the image's id plus its format extension) — wboost does not keep the
     * name a file was uploaded under, so treat it as the format, not as a
     * caption.
     *
     * Images the user has deleted sit in a trash bin for a few days; they are
     * never listed here and cannot be used. This tool only reads: nothing in
     * the gallery can be deleted, moved or renamed from an AI client. A project
     * id this account cannot see reports exactly the same failure as an id that
     * does not exist.
     *
     * @param string $projectId UUID of the project whose gallery to list, as returned by get_context in projects[].id.
     * @param null|string $directoryId UUID of the folder to list, taken from a previous call's directories[], path[] or parentDirectoryId. Omit it to list the gallery root.
     */
    #[McpTool(name: 'list_gallery')]
    public function __invoke(string $projectId, null|string $directoryId = null): ListGalleryResponse
    {
        $project = $this->project($projectId);
        $directory = $directoryId !== null ? $this->directory($project, $directoryId) : null;

        $directories = $this->fileDirectoryRepository->listChildren(
            $project->id,
            FileSource::ProjectImage,
            $directory,
        );

        // Ordering is imposed here rather than taken from the repositories: the
        // file listing is newest-first (what a human browsing wants) and an
        // agent diffing two turns needs an order that cannot wobble when a
        // picture is re-uploaded. Name then id is total — the name already ends
        // in the id, so the tiebreak is only ever reached by a rename that
        // cannot happen today.
        usort($directories, static fn (FileDirectory $a, FileDirectory $b): int =>
            [$a->name, $a->id->toString()] <=> [$b->name, $b->id->toString()]);

        $files = $this->fileUploadRepository->listByProjectSourceAndDirectory(
            $project->id,
            FileSource::ProjectImage,
            $directory,
        );

        usort($files, static fn (FileUpload $a, FileUpload $b): int =>
            [self::fileName($a), $a->id->toString()] <=> [self::fileName($b), $b->id->toString()]);

        $path = self::breadcrumb($directory);

        return new ListGalleryResponse(
            projectId: $project->id->toString(),
            projectName: $project->name,
            directoryId: $directory?->id->toString(),
            directoryName: $directory?->name,
            parentDirectoryId: $directory?->parent?->id->toString(),
            path: $path,
            directories: array_map(
                static fn (FileDirectory $child): GalleryDirectoryResponse => new GalleryDirectoryResponse(
                    id: $child->id->toString(),
                    name: $child->name,
                ),
                $directories,
            ),
            images: array_map(
                fn (FileUpload $file): GalleryImageResponse => $this->image($file),
                $files,
            ),
        );
    }

    /**
     * The project, or the one refusal this tool ever gives for a project id.
     */
    private function project(string $projectId): Project
    {
        if (!Uuid::isValid($projectId)) {
            // NOT folded into notFound(): a string that cannot be a project id
            // reveals nothing about which projects exist, and telling the agent
            // it sent a name where a UUID belongs is the difference between a
            // fixable mistake and a silent dead end.
            throw new ToolCallException(sprintf(
                '"%s" is not a valid project id. Project ids are UUIDs; call get_context to list the ones this account can reach.',
                $projectId,
            ));
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw self::notFound($projectId);
        }

        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw self::notFound($projectId);
        }

        return $project;
    }

    /**
     * A client-supplied folder id resolved ONLY if it belongs to this project's
     * image gallery — the guard that keeps a VIEW grant on one project from
     * reading another's folders. Same indistinguishability rule as
     * {@see notFound()}: another project's folder and a folder that never
     * existed produce one wording.
     */
    private function directory(Project $project, string $directoryId): FileDirectory
    {
        if (!Uuid::isValid($directoryId)) {
            throw new ToolCallException(sprintf(
                '"%s" is not a valid folder id. Folder ids are UUIDs; omit directoryId to list the gallery root.',
                $directoryId,
            ));
        }

        try {
            $directory = $this->fileDirectoryRepository->get(Uuid::fromString($directoryId));
        } catch (FileDirectoryNotFound) {
            throw self::directoryNotFound($directoryId);
        }

        // Source isolation is enforced by the single FileSource case today (the
        // gallery IS the project image library); when FileSource gains a case,
        // also guard `$directory->source === FileSource::ProjectImage` here —
        // the same note the web gallery's ownedDirectory() carries.
        if (!$directory->project->id->equals($project->id)) {
            throw self::directoryNotFound($directoryId);
        }

        return $directory;
    }

    /**
     * The refusal, worded once. Both callers — "no such row" and "not yours" —
     * must produce a byte-identical message; see the class docblock.
     */
    private static function notFound(string $projectId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Project %s was not found, or this account cannot access it. Call get_context for the projects it can reach.',
            $projectId,
        ));
    }

    /**
     * The folder counterpart of {@see notFound()} — one wording for "no such
     * folder" and "belongs to another project" alike.
     */
    private static function directoryNotFound(string $directoryId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Folder %s was not found in this project. Omit directoryId to list the gallery root, then navigate through directories[].',
            $directoryId,
        ));
    }

    private function image(FileUpload $file): GalleryImageResponse
    {
        $size = $this->pixelSize($file->path);

        return new GalleryImageResponse(
            id: $file->id->toString(),
            name: self::fileName($file),
            url: $this->uploaderHelper->getPublicPath($file->path),
            width: $size['width'],
            height: $size['height'],
        );
    }

    /**
     * The picture's own pixel size, or nulls when there is no honest answer.
     *
     * @return array{width: null|int, height: null|int}
     */
    private function pixelSize(string $path): array
    {
        $none = ['width' => null, 'height' => null];

        // An SVG is stored untouched and stays vector everywhere; it has no
        // pixel size to report, and the raster reader below would only produce
        // a false negative for it.
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return $none;
        }

        $header = $this->readHeader($path);

        if ($header === null) {
            return $none;
        }

        // Suppressed: a header too short to decode (a truncated JPEG prefix, a
        // format PHP cannot read at all) is an expected outcome here, answered
        // with nulls rather than a warning in the log.
        $size = @getimagesizefromstring($header);

        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return $none;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    /**
     * A bounded prefix of a stored file, or null when it cannot be read. The
     * stream is closed immediately, so the rest of a large object is never
     * transferred.
     */
    private function readHeader(string $path): null|string
    {
        try {
            $stream = $this->filesystem->readStream($path);
        } catch (FilesystemException) {
            return null;
        }

        $header = stream_get_contents($stream, self::HEADER_BYTES);
        fclose($stream);

        return $header === false || $header === '' ? null : $header;
    }

    /**
     * The stored object's file name. Uploads are named after the row's id plus
     * the extension describing the BYTES (the upload handler corrects a client
     * extension that contradicts them), so this is a format hint, not a label.
     */
    private static function fileName(FileUpload $file): string
    {
        return basename($file->path);
    }

    /**
     * Root → … → the listed folder, the chain a user can be told. Empty at the
     * gallery root, mirroring the web gallery's breadcrumbs.
     *
     * @return list<GalleryDirectoryResponse>
     */
    private static function breadcrumb(null|FileDirectory $directory): array
    {
        /** @var list<GalleryDirectoryResponse> $chain */
        $chain = [];

        while ($directory !== null) {
            $chain[] = new GalleryDirectoryResponse(
                id: $directory->id->toString(),
                name: $directory->name,
            );

            $directory = $directory->parent;
        }

        return array_reverse($chain);
    }
}
