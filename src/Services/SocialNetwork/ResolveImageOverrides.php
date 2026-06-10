<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;

/**
 * Validates and resolves the image-fill map supplied to a render — the image
 * counterpart of {@see ResolveTextOverrides}. For each placeholder the caller
 * filled, it:
 *
 *  - validates the chosen `imageId` belongs to the same project AND sits in a
 *    folder the designer allowed for THIS slot (security: a consumer can never
 *    reference an arbitrary upload);
 *  - enforces the per-slot move / resize / rotate limits;
 *  - honors `hide` only when the slot is `hidable`;
 *  - inlines the picture as a base64 data URI + reads its natural size.
 *
 * Unknown / unfilled slots are skipped (the designer's stand-in stays). Locked
 * adjustments, unauthorized images and malformed values raise 400.
 */
readonly final class ResolveImageOverrides
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private AssetInliner $assetInliner,
        private PlaceholderAllowedDirectories $allowedDirectories,
    ) {
    }

    /**
     * @param array<EditorImageInput> $imageInputs the variant's placeholder definitions
     * @param array<string, mixed> $provided keyed by `inputId` UUID
     */
    public function resolve(array $imageInputs, UuidInterface $projectId, array $provided): ResolvedImageOverrides
    {
        $images = [];
        $hidden = [];

        foreach ($imageInputs as $input) {
            $inputId = $input->inputId;

            if (!array_key_exists($inputId, $provided)) {
                continue;
            }

            $label = $input->name ?? $inputId;
            $parsed = $this->parseValue($label, $provided[$inputId]);

            // `hide` wins over a picture, but only when the slot permits hiding.
            if ($parsed['hide'] === true && $input->hidable) {
                $hidden[$inputId] = true;
                continue;
            }

            if ($parsed['imageId'] === null) {
                if ($parsed['scale'] !== null || $parsed['offsetX'] !== null || $parsed['offsetY'] !== null || $parsed['rotation'] !== null) {
                    throw new BadRequestHttpException(sprintf('Image input "%s" requires an "imageId" when a transform is supplied.', $label));
                }

                continue;
            }

            $file = $this->resolveFile($label, $parsed['imageId'], $projectId, $input);

            $scale = $parsed['scale'] ?? 1.0;
            $offsetX = $parsed['offsetX'] ?? 0.0;
            $offsetY = $parsed['offsetY'] ?? 0.0;
            $rotation = $parsed['rotation'] ?? 0.0;

            if (!$input->allowResize && $scale !== 1.0) {
                throw new BadRequestHttpException(sprintf('Image input "%s" cannot be resized.', $label));
            }

            if (!$input->allowMove && ($offsetX !== 0.0 || $offsetY !== 0.0)) {
                throw new BadRequestHttpException(sprintf('Image input "%s" cannot be moved.', $label));
            }

            if (!$input->allowRotate && $rotation !== 0.0) {
                throw new BadRequestHttpException(sprintf('Image input "%s" cannot be rotated.', $label));
            }

            $inlined = $this->assetInliner->inlineImageWithDimensions($file->path);
            if ($inlined === null) {
                throw new BadRequestHttpException(sprintf(
                    'Image for input "%s" could not be read or is not a supported raster image.',
                    $label,
                ));
            }

            $images[$inputId] = new ResolvedImageOverride(
                dataUri: $inlined['dataUri'],
                naturalWidth: $inlined['width'],
                naturalHeight: $inlined['height'],
                scale: $scale,
                offsetX: $offsetX,
                offsetY: $offsetY,
                rotation: $rotation,
            );
        }

        return new ResolvedImageOverrides($images, $hidden);
    }

    private function resolveFile(string $label, string $imageId, UuidInterface $projectId, EditorImageInput $input): FileUpload
    {
        if (!Uuid::isValid($imageId)) {
            throw new BadRequestHttpException(sprintf('Image input "%s" has an invalid imageId.', $label));
        }

        try {
            $file = $this->fileUploadRepository->get(Uuid::fromString($imageId));
        } catch (FileUploadNotFound) {
            throw new BadRequestHttpException(sprintf('Image input "%s": image not found.', $label));
        }

        if (!$file->project->id->equals($projectId)) {
            throw new BadRequestHttpException(sprintf('Image input "%s": image does not belong to this project.', $label));
        }

        $directoryId = $file->directory?->id->toString();

        // A root file (no folder) is usable exactly when the slot is
        // unrestricted — "nothing allowed" means the whole gallery, root included.
        if ($directoryId === null) {
            if (!$this->allowedDirectories->includesRoot($input)) {
                throw new BadRequestHttpException(sprintf('Image input "%s": image is not in an allowed folder.', $label));
            }

            return $file;
        }

        $allowedIds = $this->allowedDirectories->resolveIds($input, $projectId);

        if (!in_array($directoryId, $allowedIds, true)) {
            throw new BadRequestHttpException(sprintf('Image input "%s": image is not in an allowed folder.', $label));
        }

        return $file;
    }

    /**
     * Accepts a shorthand string (the imageId) or an object
     * `{ imageId?, scale?, offsetX?, offsetY?, rotation?, hide? }`.
     *
     * @return array{imageId: null|string, scale: null|float, offsetX: null|float, offsetY: null|float, rotation: null|float, hide: null|bool}
     */
    private function parseValue(string $label, mixed $raw): array
    {
        if (is_string($raw)) {
            return ['imageId' => $raw, 'scale' => null, 'offsetX' => null, 'offsetY' => null, 'rotation' => null, 'hide' => null];
        }

        if (!is_array($raw)) {
            throw new BadRequestHttpException(sprintf('Image input "%s" must be a string (imageId) or an object.', $label));
        }

        $imageId = null;
        if (array_key_exists('imageId', $raw)) {
            if (!is_string($raw['imageId'])) {
                throw new BadRequestHttpException(sprintf('Image input "%s".imageId must be a string.', $label));
            }
            $imageId = $raw['imageId'];
        }

        $hide = null;
        if (array_key_exists('hide', $raw)) {
            if (!is_bool($raw['hide'])) {
                throw new BadRequestHttpException(sprintf('Image input "%s".hide must be a boolean.', $label));
            }
            $hide = $raw['hide'];
        }

        return [
            'imageId' => $imageId,
            'scale' => $this->optionalFloat($label, $raw, 'scale'),
            'offsetX' => $this->optionalFloat($label, $raw, 'offsetX'),
            'offsetY' => $this->optionalFloat($label, $raw, 'offsetY'),
            'rotation' => $this->optionalFloat($label, $raw, 'rotation'),
            'hide' => $hide,
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function optionalFloat(string $label, array $raw, string $key): null|float
    {
        if (!array_key_exists($key, $raw)) {
            return null;
        }

        $value = $raw[$key];

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw new BadRequestHttpException(sprintf('Image input "%s".%s must be a number.', $label, $key));
    }
}
