<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\RichText;

/**
 * Turns a stored {@see TemplateExportVersion} back into fill-page seed state
 * (`?version=` on the fill pages) — LENIENTLY, because the design has usually
 * moved on since the export: inputs may be gone, a gallery picture may be
 * trashed / purged / moved out of the slot's allowed folders, a rich input may
 * have become plain. A version must always LOAD; whatever no longer applies
 * silently falls back to the designed state, mirroring how the render
 * resolvers skip unknown ids — with one difference: where the resolvers 400
 * (disallowed image, forbidden transform), the seeder DROPS the offending
 * piece instead, so a seeded page can always export again.
 */
readonly final class ExportVersionSeeder
{
    public function __construct(
        private FileUploadRepository $fileUploadRepository,
        private PlaceholderAllowedDirectories $allowedDirectories,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * Seed state for the single-variant fill page (the Live component's mount
     * props + the image-restore contract of `variant_image_fill_controller`).
     *
     * @return array{
     *     textValues: array<string, string>,
     *     hiddenValues: array<string, bool>,
     *     imageValues: array<string, array<string, mixed>>,
     * }
     */
    public function forVariant(TemplateExportVersion $version, TemplateVariant $variant): array
    {
        $values = $version->fillValues;
        $hiddenIds = array_flip($values->hidden);

        $textValues = [];
        $hiddenValues = [];

        foreach ($variant->inputs as $input) {
            if ($input->locked) {
                continue;
            }

            $stored = $values->texts[$input->inputId] ?? null;

            if (is_string($stored)) {
                $textValues[$input->inputId] = $this->degradeToInput($stored, $input);
            }

            if ($input->hidable && isset($hiddenIds[$input->inputId])) {
                $hiddenValues[$input->inputId] = true;
            }
        }

        $imageValues = [];

        foreach ($variant->imageInputs as $input) {
            $entry = $values->images[$input->inputId] ?? null;

            if (!is_array($entry)) {
                continue;
            }

            $seed = $this->imageSeed($entry, $input, $variant->template->project->id);

            if ($seed !== []) {
                $imageValues[$input->inputId] = $seed;
            }
        }

        return [
            'textValues' => $textValues,
            'hiddenValues' => $hiddenValues,
            'imageValues' => $imageValues,
        ];
    }

    /**
     * Seed state for the group fill page (server-rendered form values + the
     * `group_fill_controller` seed contract).
     *
     * @param list<EditorTextInput> $textInputs the page's unified text inputs
     * @param list<EditorImageInput> $imageInputs the page's unified image slots
     * @param list<TemplateVariant> $memberVariants
     * @return array{
     *     texts: array<string, string>,
     *     hidden: array<string, bool>,
     *     images: array<string, array{imageId: string, url: string}>,
     *     imageHidden: array<string, bool>,
     *     placements: array<string, array<string, array<string, float>>>,
     * }
     */
    public function forGroup(
        TemplateExportVersion $version,
        UuidInterface $projectId,
        array $textInputs,
        array $imageInputs,
        array $memberVariants,
    ): array {
        $values = $version->fillValues;
        $hiddenIds = array_flip($values->hidden);

        $texts = [];
        $hidden = [];

        foreach ($textInputs as $input) {
            $stored = $values->texts[$input->inputId] ?? null;

            if (is_string($stored) && $stored !== '') {
                $texts[$input->inputId] = $this->degradeToInput($stored, $input);
            }

            if ($input->hidable && isset($hiddenIds[$input->inputId])) {
                $hidden[$input->inputId] = true;
            }
        }

        $images = [];
        $imageHidden = [];
        $slotsById = [];

        foreach ($imageInputs as $input) {
            $slotsById[$input->inputId] = $input;
            $entry = $values->images[$input->inputId] ?? null;

            if (!is_array($entry)) {
                continue;
            }

            if (($entry['hide'] ?? false) === true && $input->hidable) {
                $imageHidden[$input->inputId] = true;
                continue;
            }

            $imageId = $entry['imageId'] ?? null;

            if (!is_string($imageId)) {
                continue;
            }

            $file = $this->usableFile($imageId, $input, $projectId);

            if ($file !== null) {
                $images[$input->inputId] = [
                    'imageId' => $imageId,
                    'url' => $this->uploaderHelper->getPublicPath($file->path),
                ];
            }
        }

        $memberIds = array_flip(array_map(
            static fn (TemplateVariant $variant): string => $variant->id->toString(),
            $memberVariants,
        ));

        $placements = [];

        foreach ($version->fillValues->placements as $variantId => $slots) {
            if (!isset($memberIds[$variantId])) {
                continue;
            }

            foreach ($slots as $inputId => $placement) {
                $input = $slotsById[$inputId] ?? null;

                // Placement only means something for a slot that still has a
                // seeded picture and still allows adjusting.
                if ($input === null || !isset($images[$inputId])) {
                    continue;
                }

                $allowed = $this->allowedTransform($placement, $input);

                if ($allowed !== []) {
                    $placements[$variantId][$inputId] = $allowed;
                }
            }
        }

        return [
            'texts' => $texts,
            'hidden' => $hidden,
            'images' => $images,
            'imageHidden' => $imageHidden,
            'placements' => $placements,
        ];
    }

    /**
     * A rich envelope stored for an input that is no longer rich would show up
     * as raw JSON in a plain field — degrade it to its plain-text concat. The
     * other direction (plain string on a now-rich input) needs nothing: the
     * whole rich pipeline treats a plain string as one unstyled run.
     */
    private function degradeToInput(string $stored, EditorTextInput $input): string
    {
        if ($input->richText) {
            return $stored;
        }

        $envelope = RichText::tryExtractEnvelope($stored);

        if ($envelope === null) {
            return $stored;
        }

        $plain = '';

        foreach ($envelope['runs'] as $run) {
            if (is_array($run) && is_string($run['text'] ?? null)) {
                $plain .= $run['text'];
            }
        }

        return $plain;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function imageSeed(array $entry, EditorImageInput $input, UuidInterface $projectId): array
    {
        $seed = [];

        if (($entry['hide'] ?? false) === true && $input->hidable) {
            // Hide wins over a picture — same precedence as the resolver.
            return ['hide' => true];
        }

        $imageId = $entry['imageId'] ?? null;

        if (!is_string($imageId)) {
            return [];
        }

        $file = $this->usableFile($imageId, $input, $projectId);

        if ($file === null) {
            return [];
        }

        $seed['imageId'] = $imageId;
        $seed['url'] = $this->uploaderHelper->getPublicPath($file->path);

        return [...$seed, ...$this->allowedTransform($entry, $input)];
    }

    /**
     * Keep only the transform fields the slot still permits — a stored scale
     * on a slot that no longer allows resizing would 400 the re-export.
     *
     * @param array<string, mixed> $entry
     * @return array<string, float>
     */
    private function allowedTransform(array $entry, EditorImageInput $input): array
    {
        $fields = [];

        if ($input->allowResize) {
            $fields[] = 'scale';
        }
        if ($input->allowMove) {
            array_push($fields, 'offsetX', 'offsetY', 'offsetXRatio', 'offsetYRatio');
        }
        if ($input->allowRotate) {
            $fields[] = 'rotation';
        }

        $transform = [];

        foreach ($fields as $field) {
            $value = $entry[$field] ?? null;
            if (is_numeric($value)) {
                $transform[$field] = (float) $value;
            }
        }

        return $transform;
    }

    /**
     * The mirror of ResolveImageOverrides::resolveFile, answering null where
     * the resolver throws: exists, same project, not trashed, in an allowed
     * folder.
     */
    private function usableFile(string $imageId, EditorImageInput $input, UuidInterface $projectId): null|FileUpload
    {
        if (!Uuid::isValid($imageId)) {
            return null;
        }

        try {
            $file = $this->fileUploadRepository->get(Uuid::fromString($imageId));
        } catch (FileUploadNotFound) {
            return null;
        }

        if (!$file->project->id->equals($projectId) || $file->isTrashed()) {
            return null;
        }

        $directoryId = $file->directory?->id->toString();

        if ($directoryId === null) {
            return $this->allowedDirectories->includesRoot($input) ? $file : null;
        }

        return in_array($directoryId, $this->allowedDirectories->resolveIds($input, $projectId), true)
            ? $file
            : null;
    }
}
