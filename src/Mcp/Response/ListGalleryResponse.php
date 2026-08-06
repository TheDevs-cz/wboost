<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The reply of the `list_gallery` MCP tool — ONE level of a project's image
 * library: the folders directly inside the listed one, and the pictures that
 * live in it.
 *
 * One level rather than the whole tree on purpose. A gallery is a filesystem
 * whose depth nobody bounds; serialising all of it would put an unpredictable
 * amount of text in front of the model to answer "what pictures are here", and
 * the agent still has to pick a folder. Navigation is therefore explicit:
 * `directories` goes down, `parentDirectoryId` goes up, `path` says where you
 * are.
 *
 * `directoryId` / `directoryName` describe the folder being listed and are BOTH
 * null at the gallery root. The root is a real location that holds pictures —
 * not merely a container for folders — so an empty `directories` there means
 * the project files everything flat, never that it has no images.
 *
 * `path` is the breadcrumb from the root down to AND INCLUDING the listed
 * folder (empty at the root), so an agent can name the current location to a
 * user without remembering how it got there. `parentDirectoryId` is that
 * breadcrumb's second-to-last entry, stated as its own field because "go back
 * up" is the one move an agent makes most and should not have to compute.
 *
 * Images in the trash are absent from every listing, including the root — a
 * trashed picture is detached from its folder, so the root is exactly where a
 * missing filter would surface it. There is no trash folder in `directories`
 * either: the bin is not a place this tool can navigate to.
 */
readonly final class ListGalleryResponse
{
    /**
     * @param list<GalleryDirectoryResponse> $path
     * @param list<GalleryDirectoryResponse> $directories
     * @param list<GalleryImageResponse> $images
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public null|string $directoryId,
        public null|string $directoryName,
        public null|string $parentDirectoryId,
        public array $path,
        public array $directories,
        public array $images,
    ) {
    }
}
