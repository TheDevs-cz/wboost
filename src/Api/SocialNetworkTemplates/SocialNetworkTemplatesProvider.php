<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\RichTextFontOption;

/**
 * @implements ProviderInterface<SocialNetworkTemplateResponse>
 */
final readonly class SocialNetworkTemplatesProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private UploaderHelper $uploaderHelper,
        private ProjectRepository $projectRepository,
        private CanvasPlaceholderGeometry $placeholderGeometry,
        private TextInputObjectBinder $textInputObjectBinder,
        private PlaceholderAllowedDirectories $allowedDirectories,
        private ResolveRichTextOptions $resolveRichTextOptions,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return list<SocialNetworkTemplateResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        $projectId = $uriVariables['projectId'] ?? null;

        if (!is_string($projectId) || !Uuid::isValid($projectId)) {
            throw new BadRequestHttpException('Invalid project id.');
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw new NotFoundHttpException();
        }

        // Same visibility rule as the web UI (ProjectVoter): owner, admin, or
        // a user the project is shared with. 404 (not 403) so foreign
        // projects' existence isn't leaked.
        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw new NotFoundHttpException();
        }

        /** @var list<SocialNetworkTemplate> $templates */
        $templates = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(SocialNetworkTemplate::class, 't')
            ->where('t.project = :project')
            ->setParameter('project', $project->id->toString())
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            fn (SocialNetworkTemplate $template): SocialNetworkTemplateResponse => $this->buildTemplate($template),
            $templates,
        );
    }

    private function buildTemplate(SocialNetworkTemplate $template): SocialNetworkTemplateResponse
    {
        return new SocialNetworkTemplateResponse(
            id: $template->id->toString(),
            name: $template->name,
            position: $template->position,
            categoryId: $template->category?->id->toString(),
            categoryName: $template->category?->name,
            createdAt: $template->createdAt,
            variants: array_values(array_map(
                fn (SocialNetworkTemplateVariant $variant): SocialNetworkTemplateVariantResponse => $this->buildVariant($variant),
                $template->variants(),
            )),
        );
    }

    private function buildVariant(SocialNetworkTemplateVariant $variant): SocialNetworkTemplateVariantResponse
    {
        $decoded = json_decode($variant->canvas, true);
        /** @var array<string, mixed> $canvas */
        $canvas = is_array($decoded) ? $decoded : [];
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);
        $containers = CanvasContainer::collectionFromCanvas($canvas);

        return new SocialNetworkTemplateVariantResponse(
            id: $variant->id->toString(),
            dimension: $variant->dimension->value,
            width: $variant->dimension->width(),
            height: $variant->dimension->height(),
            previewImageUrl: $variant->previewImagePath !== null
                ? $this->uploaderHelper->getPublicPath($variant->previewImagePath)
                : null,
            backgroundImageUrl: $this->uploaderHelper->getPublicPath($variant->backgroundImage),
            thumbnailUrl: $this->urlGenerator->generate(
                'api_social_network_template_variant_thumbnail',
                ['variantId' => $variant->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            exportUrl: $this->urlGenerator->generate(
                'api_social_network_template_variant_export',
                ['id' => $variant->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            inputs: $this->buildTextInputs($variant, $canvas, $containers),
            imageInputs: $this->buildImageInputs($variant),
            containers: $this->buildContainers($containers, $frames),
            richTextOptions: $this->buildRichTextOptions($variant),
        );
    }

    /**
     * Fonts + swatches for the variant's WYSIWYG inputs. Computed (fonts +
     * manuals queries) only when the variant actually has a rich input.
     */
    private function buildRichTextOptions(SocialNetworkTemplateVariant $variant): null|RichTextOptionsResponse
    {
        $hasRichInput = false;
        foreach ($variant->inputs as $input) {
            if ($input->richText && !$input->locked) {
                $hasRichInput = true;
                break;
            }
        }

        if (!$hasRichInput) {
            return null;
        }

        $options = $this->resolveRichTextOptions->forVariant($variant);

        return new RichTextOptionsResponse(
            fonts: array_map(
                static fn (RichTextFontOption $font): RichTextFontOptionResponse => new RichTextFontOptionResponse(
                    family: $font->family,
                    fontName: $font->fontName,
                    faceName: $font->faceName,
                    weight: $font->weight,
                    style: $font->style,
                    url: $font->url,
                ),
                $options->fonts,
            ),
            colors: $options->colors,
        );
    }

    /**
     * @param array<string, mixed> $canvas
     * @param list<CanvasContainer> $containers
     * @return list<SocialNetworkTemplateVariantInputResponse>
     */
    private function buildTextInputs(SocialNetworkTemplateVariant $variant, array $canvas, array $containers): array
    {
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);
        $textStyles = $this->textInputObjectBinder->textStylesByInputId($canvas, $variant->inputs);
        $layerIndexes = $this->textInputObjectBinder->layerIndexesByInputId($canvas, $variant->inputs);

        $containerIdByInputId = [];
        foreach ($containers as $container) {
            foreach ($container->memberInputIds as $memberInputId) {
                $containerIdByInputId[$memberInputId] ??= $container->id;
            }
        }

        return array_values(array_map(
            function (EditorTextInput $input) use ($frames, $textStyles, $containerIdByInputId, $layerIndexes): SocialNetworkTemplateVariantInputResponse {
                $frame = $frames[$input->inputId] ?? null;
                $textStyle = $textStyles[$input->inputId] ?? null;

                return new SocialNetworkTemplateVariantInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    maxLength: $input->maxLength,
                    locked: $input->locked,
                    uppercase: $input->uppercase,
                    description: $input->description,
                    hidable: $input->hidable,
                    richText: $input->richText,
                    frame: $frame !== null
                        ? new SocialNetworkTemplateVariantInputFrameResponse(
                            $frame->x,
                            $frame->y,
                            $frame->width,
                            $frame->height,
                        )
                        : null,
                    containerId: $containerIdByInputId[$input->inputId] ?? null,
                    textStyle: $textStyle !== null
                        ? new SocialNetworkTemplateVariantInputTextStyleResponse(
                            fontFamily: $textStyle['fontFamily'],
                            fontSize: $textStyle['fontSize'],
                            lineHeight: $textStyle['lineHeight'],
                            charSpacing: $textStyle['charSpacing'],
                            textAlign: $textStyle['textAlign'],
                        )
                        : null,
                    layerIndex: $layerIndexes[$input->inputId] ?? null,
                );
            },
            $variant->inputs,
        ));
    }

    /**
     * Container definitions with the zone anchor resolved: `y` is the designed
     * top of the first member (flow order) that has a frame — the coordinate a
     * consumer draws the zone from. A container whose members can't be located
     * on the canvas is omitted (it cannot reflow anything at render time).
     *
     * @param list<CanvasContainer> $containers
     * @param array<string, \WBoost\Web\Value\PlaceholderFrame> $frames
     * @return list<SocialNetworkTemplateVariantContainerResponse>
     */
    private function buildContainers(array $containers, array $frames): array
    {
        $result = [];
        foreach ($containers as $container) {
            $y = null;
            foreach ($container->memberInputIds as $memberInputId) {
                if (isset($frames[$memberInputId])) {
                    $y = $frames[$memberInputId]->y;
                    break;
                }
            }
            if ($y === null) {
                continue;
            }

            $result[] = new SocialNetworkTemplateVariantContainerResponse(
                id: $container->id,
                maxHeight: $container->maxHeight,
                y: $y,
                memberInputIds: $container->memberInputIds,
            );
        }

        return $result;
    }

    /**
     * @return list<SocialNetworkTemplateVariantImageInputResponse>
     */
    private function buildImageInputs(SocialNetworkTemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);
        $layerIndexes = $this->placeholderGeometry->placeholderObjectIndexesByInputId($canvas);
        $projectId = $variant->template->project->id;

        return array_values(array_map(
            function (EditorImageInput $input) use ($objects, $layerIndexes, $projectId): SocialNetworkTemplateVariantImageInputResponse {
                $object = $objects[$input->inputId] ?? null;

                $frame = null;
                $defaultImageUrl = null;

                if ($object !== null) {
                    $placeholderFrame = $this->placeholderGeometry->frameFromObject($object);
                    if ($placeholderFrame !== null) {
                        $frame = new SocialNetworkTemplateVariantImageInputFrameResponse(
                            $placeholderFrame->x,
                            $placeholderFrame->y,
                            $placeholderFrame->width,
                            $placeholderFrame->height,
                        );
                    }

                    $defaultImageUrl = $this->defaultImageUrl($object);
                }

                return new SocialNetworkTemplateVariantImageInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    description: $input->description,
                    allowMove: $input->allowMove,
                    allowResize: $input->allowResize,
                    allowRotate: $input->allowRotate,
                    hidable: $input->hidable,
                    allowedDirectoryIds: $input->allowedDirectoryIds,
                    directories: array_map(
                        static fn (FileDirectory $directory): PlaceholderDirectoryResponse => new PlaceholderDirectoryResponse(
                            id: $directory->id->toString(),
                            name: $directory->name,
                        ),
                        $this->allowedDirectories->resolve($input, $projectId),
                    ),
                    includesRoot: $this->allowedDirectories->includesRoot($input),
                    frame: $frame,
                    defaultImageUrl: $defaultImageUrl,
                    layerIndex: $layerIndexes[$input->inputId] ?? null,
                );
            },
            $variant->imageInputs,
        ));
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
