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
     * pictures it may be filled from (scoped to the slot's allowed folders)
     * and the designer's stand-in as the "keep default" preview.
     *
     * @param list<SocialNetworkTemplateVariant|CustomTemplateVariant> $variants
     * @return list<array{
     *     input: EditorImageInput,
     *     defaultImageUrl: null|string,
     *     images: list<array{id: string, url: string}>
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
            ];
        }

        return $result;
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
