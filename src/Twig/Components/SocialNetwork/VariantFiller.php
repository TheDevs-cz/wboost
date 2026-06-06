<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\ResolvedImageOverrides;

/**
 * Live Component that powers the user-fill / export page for a Social Network
 * Template Variant.
 *
 * Stage 5 split this off from the legacy Stimulus + client-side Fabric
 * controller; the text preview is server-rendered via the same Gotenberg
 * pipeline the API uses. The image-placeholder feature layers a HYBRID on top:
 * when the variant has fillable image slots, the page renders an interactive
 * Fabric canvas (the `variant-image-fill` controller) whose backdrop is the
 * server render with the placeholders hidden ({@see backdropDataUri()}); the
 * user's chosen pictures float on top as live Fabric objects. Text still flows
 * through the server backdrop (no client-side fonts). Either way the final
 * download / API export is the full server render, so the produced PNG is
 * authoritative.
 *
 * Authorisation note: `#[IsGranted]` cannot be applied at class level — the
 * Symfony Security listener resolves the subject from method arguments, and a
 * Live Component's `$variant` is a hydrated LiveProp (class property), not an
 * argument. Access is enforced explicitly in the render methods and
 * `postMount()`, which are the only paths that touch the variant.
 */
#[AsLiveComponent('SocialNetwork:VariantFiller')]
final class VariantFiller extends AbstractController
{
    use DefaultActionTrait;

    /**
     * The variant being filled. Live Components hydrate Doctrine entities by
     * id, so this value flows through round-trips as the variant's UUID.
     *
     * Declared nullable to satisfy PHPStan's uninitialized-property check —
     * Live Components hydrate the property after construction, so a non-null
     * default is not possible at the language level. In practice it is always
     * set when the component renders (see assert()s below).
     */
    #[LiveProp]
    public null|SocialNetworkTemplateVariant $variant = null;

    /**
     * Map of inputId UUID → text value the user has typed.
     *
     * `writable: true` lets Live Components write into any sub-key of this
     * array via `data-model="textValues[<inputId>]"` in the template.
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $textValues = [];

    /**
     * Map of inputId UUID → bool (true = hide). Only inputs whose definition
     * has `hidable: true` honor this; others are silently ignored.
     *
     * @var array<string, bool>
     */
    #[LiveProp(writable: true)]
    public array $hiddenValues = [];

    public function __construct(
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly SocialNetworkTemplateVariantImageRendererInterface $renderer,
        private readonly CanvasPlaceholderGeometry $placeholderGeometry,
        private readonly PlaceholderAllowedDirectories $allowedDirectories,
        private readonly FileUploadRepository $fileUploadRepository,
        private readonly UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * Pre-populate `textValues` / `hiddenValues` with an entry per non-locked
     * input so the Live Component value-store knows about every inputId key
     * from the first render.
     */
    #[PostMount]
    public function postMount(): void
    {
        $variant = $this->variant;

        if ($variant === null) {
            return;
        }

        $this->denyAccessUnlessGranted(SocialNetworkTemplateVariantVoter::VIEW, $variant);

        foreach ($variant->inputs as $input) {
            if ($input->locked) {
                continue;
            }

            $this->textValues[$input->inputId] ??= '';

            if ($input->hidable) {
                $this->hiddenValues[$input->inputId] ??= false;
            }
        }
    }

    public function hasImagePlaceholders(): bool
    {
        $variant = $this->variant;
        assert($variant !== null);

        return $variant->imageInputs !== [];
    }

    /**
     * The plain server preview (text + background + the designer's stand-in
     * placeholders inlined) for variants WITHOUT fillable image slots — the
     * pre-image-feature behaviour. See {@see backdropDataUri()} for the image
     * case.
     */
    public function previewDataUri(): string
    {
        return $this->renderToDataUri(ResolvedImageOverrides::none());
    }

    /**
     * The interactive canvas backdrop: the server render with every image
     * placeholder HIDDEN, so the live Fabric image objects the user positions
     * are the only pictures shown in those slots. Re-rendered on each text edit
     * (Live re-render) and picked up by the fill controller.
     */
    public function backdropDataUri(): string
    {
        $variant = $this->variant;
        assert($variant !== null);

        $hidden = [];
        foreach ($variant->imageInputs as $input) {
            $hidden[$input->inputId] = true;
        }

        return $this->renderToDataUri(new ResolvedImageOverrides([], $hidden));
    }

    /**
     * Per-placeholder data for the fill controller + picker: the designer's
     * frame, the user limits, the stand-in url, and the gallery images the slot
     * may be filled from (already scoped to the allowed folders).
     *
     * @return list<array{
     *     inputId: string,
     *     name: null|string,
     *     description: null|string,
     *     allowMove: bool,
     *     allowResize: bool,
     *     allowRotate: bool,
     *     hidable: bool,
     *     frame: null|array{x: float, y: float, width: float, height: float},
     *     defaultImageUrl: null|string,
     *     images: list<array{id: string, url: string}>,
     *     canUpload: bool
     * }>
     */
    public function imagePlaceholders(): array
    {
        $variant = $this->variant;
        assert($variant !== null);
        $this->denyAccessUnlessGranted(SocialNetworkTemplateVariantVoter::VIEW, $variant);

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);
        $project = $variant->template->project;

        $result = [];
        foreach ($variant->imageInputs as $input) {
            $object = $objects[$input->inputId] ?? null;

            $frame = null;
            $defaultImageUrl = null;
            if ($object !== null) {
                $placeholderFrame = $this->placeholderGeometry->frameFromObject($object);
                if ($placeholderFrame !== null) {
                    $frame = [
                        'x' => $placeholderFrame->x,
                        'y' => $placeholderFrame->y,
                        'width' => $placeholderFrame->width,
                        'height' => $placeholderFrame->height,
                    ];
                }
                $defaultImageUrl = $this->defaultImageUrl($object);
            }

            // Effective folders the slot may be filled from (empty allow-list =
            // every project folder). With none at all the user can neither pick
            // nor upload, so the template hides the upload field and explains why.
            $directories = $this->allowedDirectories->resolve($input, $project->id);

            $result[] = [
                'inputId' => $input->inputId,
                'name' => $input->name,
                'description' => $input->description,
                'allowMove' => $input->allowMove,
                'allowResize' => $input->allowResize,
                'allowRotate' => $input->allowRotate,
                'hidable' => $input->hidable,
                'frame' => $frame,
                'defaultImageUrl' => $defaultImageUrl,
                'images' => $this->allowedImages($project->id, $directories),
                'canUpload' => $directories !== [],
            ];
        }

        return $result;
    }

    private function renderToDataUri(ResolvedImageOverrides $imageOverrides): string
    {
        $variant = $this->variant;
        assert($variant !== null);
        $this->denyAccessUnlessGranted(SocialNetworkTemplateVariantVoter::VIEW, $variant);

        $overrides = $this->resolveTextOverrides->resolve($variant->inputs, $this->buildProvidedValues());
        $bytes = $this->renderer->renderToBytes($variant, $overrides, $imageOverrides);

        if ($bytes === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    /**
     * @param \Ramsey\Uuid\UuidInterface $projectId
     * @param list<FileDirectory> $directories the slot's effective allowed folders
     * @return list<array{id: string, url: string}>
     */
    private function allowedImages(\Ramsey\Uuid\UuidInterface $projectId, array $directories): array
    {
        if ($directories === []) {
            return [];
        }

        $directoryIds = array_map(static fn (FileDirectory $directory): \Ramsey\Uuid\UuidInterface => $directory->id, $directories);

        return array_map(
            fn (FileUpload $file): array => [
                'id' => $file->id->toString(),
                'url' => $this->uploaderHelper->getPublicPath($file->path),
            ],
            $this->fileUploadRepository->listByProjectSourceAndDirectories($projectId, FileSource::SocialNetworkImage, $directoryIds),
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

    /**
     * Merge the two writable LiveProps into the shape ResolveTextOverrides
     * expects: `{ inputId: { value?: string, hide?: bool } }`.
     *
     * @return array<string, array{value?: string, hide?: bool}>
     */
    private function buildProvidedValues(): array
    {
        /** @var array<string, array{value?: string, hide?: bool}> $merged */
        $merged = [];

        foreach ($this->textValues as $inputId => $value) {
            $merged[$inputId] = ['value' => $value];
        }

        foreach ($this->hiddenValues as $inputId => $hide) {
            if (!isset($merged[$inputId])) {
                $merged[$inputId] = [];
            }
            $merged[$inputId]['hide'] = $hide;
        }

        return $merged;
    }
}
