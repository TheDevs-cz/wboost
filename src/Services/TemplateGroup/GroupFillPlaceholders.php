<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateGroup;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;

/**
 * Collects the UNIFIED placeholder list of a template group for the
 * fill & export page: one entry per distinct `inputId` across all member
 * variants, so the user fills each placeholder once and the value fans out
 * to every dimension that carries it.
 *
 * The shared inputId UUID is the group join key (group-created variants copy
 * the source design's ids verbatim), so deduping by it is exact. The FIRST
 * occurrence wins for metadata (name, limits) — member definitions start as
 * identical copies; should they diverge later, each variant's own definition
 * still governs its render (the resolvers work per variant), only the form
 * chrome follows the first one.
 */
readonly final class GroupFillPlaceholders
{
    public function __construct(
        private PlaceholderAllowedDirectories $allowedDirectories,
        private FileUploadRepository $fileUploadRepository,
        private UploaderHelper $uploaderHelper,
        private CanvasPlaceholderGeometry $placeholderGeometry,
    ) {
    }

    /**
     * Locked inputs are excluded — they cannot be overridden anywhere.
     *
     * @param list<SocialNetworkTemplateVariant|CustomTemplateVariant> $variants
     * @return list<EditorTextInput>
     */
    public function textInputs(array $variants): array
    {
        /** @var array<string, EditorTextInput> $unified */
        $unified = [];

        foreach ($variants as $variant) {
            foreach ($variant->inputs as $input) {
                if ($input->locked) {
                    continue;
                }

                $unified[$input->inputId] ??= $input;
            }
        }

        return array_values($unified);
    }

    /**
     * One unified image slot per distinct placeholder, with the gallery
     * pictures it may be filled from (scoped to the slot's allowed folders),
     * the folders a user may upload their OWN picture into, and the designer's
     * stand-in as the "keep default" preview.
     *
     * @param list<SocialNetworkTemplateVariant|CustomTemplateVariant> $variants
     * @return list<array{
     *     input: EditorImageInput,
     *     defaultImageUrl: null|string,
     *     images: list<array{id: string, url: string}>,
     *     directories: list<array{id: string, name: string}>,
     *     includesRoot: bool,
     *     canUpload: bool
     * }>
     */
    public function imageInputs(array $variants, UuidInterface $projectId): array
    {
        /** @var array<string, EditorImageInput> $unified */
        $unified = [];
        /** @var array<string, null|string> $defaultUrls */
        $defaultUrls = [];

        foreach ($variants as $variant) {
            $decoded = json_decode($variant->canvas, true);
            $canvas = is_array($decoded) ? $decoded : [];
            $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);

            foreach ($variant->imageInputs as $input) {
                $unified[$input->inputId] ??= $input;

                // First variant that yields a resolvable stand-in wins.
                if (($defaultUrls[$input->inputId] ?? null) === null) {
                    $object = $objects[$input->inputId] ?? null;
                    $defaultUrls[$input->inputId] = $object !== null ? $this->defaultImageUrl($object) : null;
                }
            }
        }

        $result = [];

        foreach ($unified as $inputId => $input) {
            $directories = $this->allowedDirectories->resolve($input, $projectId);
            $includesRoot = $this->allowedDirectories->includesRoot($input);

            $result[] = [
                'input' => $input,
                'defaultImageUrl' => $defaultUrls[$inputId] ?? null,
                'images' => $this->allowedImages($projectId, $directories, $includesRoot),
                // Upload targets, mirroring the per-variant fill page: with
                // several possible targets the user picks one (the server
                // refuses to guess); a single folder — or the root of an
                // unrestricted slot — is resolved server-side. Only a
                // restricted slot whose every folder was deleted is a dead end.
                'directories' => array_map(
                    static fn (FileDirectory $directory): array => [
                        'id' => $directory->id->toString(),
                        'name' => $directory->name,
                    ],
                    $directories,
                ),
                'includesRoot' => $includesRoot,
                'canUpload' => $directories !== [] || $includesRoot,
            ];
        }

        return $result;
    }

    /**
     * The image placeholders' frames (canvas px) as designed in ONE variant,
     * keyed by inputId.
     *
     * The same placeholder occupies a different rectangle in every dimension,
     * which is exactly why the group fill page carries pans as a fraction of the
     * frame rather than in pixels: the fill page hands these frames to the
     * client so it can draw each dimension's picture where the server will
     * render it, and resolve one shared relative placement into that dimension's
     * pixels.
     *
     * @return array<string, array{x: float, y: float, width: float, height: float}>
     */
    public function imageFrames(SocialNetworkTemplateVariant|CustomTemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];

        $frames = [];

        foreach ($this->placeholderGeometry->placeholderObjectsByInputId($canvas) as $inputId => $object) {
            $frame = $this->placeholderGeometry->frameFromObject($object);

            if ($frame === null) {
                continue;
            }

            $frames[$inputId] = [
                'x' => $frame->x,
                'y' => $frame->y,
                'width' => $frame->width,
                'height' => $frame->height,
            ];
        }

        return $frames;
    }

    /**
     * @param list<FileDirectory> $directories
     * @return list<array{id: string, url: string}>
     */
    private function allowedImages(UuidInterface $projectId, array $directories, bool $includeRoot): array
    {
        $directoryIds = array_map(static fn (FileDirectory $directory): UuidInterface => $directory->id, $directories);

        return array_map(
            fn (FileUpload $file): array => [
                'id' => $file->id->toString(),
                'url' => $this->uploaderHelper->getPublicPath($file->path),
            ],
            $this->fileUploadRepository->listByProjectSourceAndDirectories($projectId, FileSource::ProjectImage, $directoryIds, $includeRoot),
        );
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private function defaultImageUrl(array $object): null|string
    {
        $assetPath = $object['assetPath'] ?? null;
        if (is_string($assetPath) && $assetPath !== '') {
            return $this->uploaderHelper->getPublicPath($assetPath);
        }

        $src = $object['src'] ?? null;
        if (is_string($src) && $src !== '' && !str_starts_with($src, 'data:')) {
            return $src;
        }

        return null;
    }
}
