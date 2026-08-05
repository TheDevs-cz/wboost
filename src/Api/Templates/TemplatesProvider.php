<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\FileDirectory;
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
use WBoost\Web\Value\ResolvedListStyle;
use WBoost\Web\Value\RichTextFontOption;

/**
 * @implements ProviderInterface<TemplateResponse>
 */
final readonly class TemplatesProvider implements ProviderInterface
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
     * @return list<TemplateResponse>
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

        /** @var list<Template> $templates */
        $templates = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Template::class, 't')
            ->where('t.project = :project')
            ->setParameter('project', $project->id->toString())
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            fn (Template $template): TemplateResponse => $this->buildTemplate($template),
            $templates,
        );
    }

    private function buildTemplate(Template $template): TemplateResponse
    {
        return new TemplateResponse(
            id: $template->id->toString(),
            name: $template->name,
            position: $template->position,
            categoryId: $template->category?->id->toString(),
            categoryName: $template->category?->name,
            createdAt: $template->createdAt,
            variants: array_values(array_map(
                fn (TemplateVariant $variant): TemplateVariantResponse => $this->buildVariant($variant),
                $template->variants(),
            )),
        );
    }

    private function buildVariant(TemplateVariant $variant): TemplateVariantResponse
    {
        $decoded = json_decode($variant->canvas, true);
        /** @var array<string, mixed> $canvas */
        $canvas = is_array($decoded) ? $decoded : [];
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);
        $containers = CanvasContainer::collectionFromCanvas($canvas);

        return new TemplateVariantResponse(
            id: $variant->id->toString(),
            dimension: $variant->dimension->label(),
            preset: $variant->dimension->preset?->value,
            unit: $variant->dimension->unit->value,
            unitWidth: $variant->dimension->unitWidth,
            unitHeight: $variant->dimension->unitHeight,
            width: $variant->dimension->width(),
            height: $variant->dimension->height(),
            previewImageUrl: $variant->previewImagePath !== null
                ? $this->uploaderHelper->getPublicPath($variant->previewImagePath)
                : null,
            backgroundImageUrl: $variant->backgroundImage !== null
                ? $this->uploaderHelper->getPublicPath($variant->backgroundImage)
                : null,
            thumbnailUrl: $this->urlGenerator->generate(
                'api_template_variant_thumbnail',
                ['variantId' => $variant->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            exportUrl: $this->urlGenerator->generate(
                'api_template_variant_export',
                ['id' => $variant->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            inputs: $this->buildTextInputs($variant, $canvas, $containers),
            imageInputs: $this->buildImageInputs($variant),
            containers: $this->buildContainers($containers, $frames, $variant->inputs),
            richTextOptions: $this->buildRichTextOptions($variant),
        );
    }

    /**
     * Fonts + swatches for the variant's WYSIWYG inputs. Computed (fonts +
     * manuals queries) only when the variant actually has a rich input.
     */
    private function buildRichTextOptions(TemplateVariant $variant): null|RichTextOptionsResponse
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
     * @return list<TemplateVariantInputResponse>
     */
    private function buildTextInputs(TemplateVariant $variant, array $canvas, array $containers): array
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
            function (EditorTextInput $input) use ($frames, $textStyles, $containerIdByInputId, $layerIndexes): TemplateVariantInputResponse {
                $frame = $frames[$input->inputId] ?? null;
                $textStyle = $textStyles[$input->inputId] ?? null;

                $listStyle = null;
                if ($input->richText && $input->lists) {
                    $resolved = ResolvedListStyle::resolve(
                        $input,
                        fontSize: (float) ($textStyle['fontSize'] ?? 40),
                        lineHeight: (float) ($textStyle['lineHeight'] ?? 1.16),
                    );
                    $listStyle = new TemplateVariantListStyleResponse(
                        bullet: $resolved->bullet,
                        bulletImageUrl: $resolved->bulletImage !== null
                            ? $this->uploaderHelper->getPublicPath($resolved->bulletImage)
                            : null,
                        indent: $resolved->indent,
                        itemSpacing: $resolved->itemSpacing,
                        blockSpacing: $resolved->blockSpacing,
                    );
                }

                return new TemplateVariantInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    maxLength: $input->maxLength,
                    locked: $input->locked,
                    uppercase: $input->uppercase,
                    description: $input->description,
                    hidable: $input->hidable,
                    richText: $input->richText,
                    lists: $input->richText && $input->lists,
                    listStyle: $listStyle,
                    frame: $frame !== null
                        ? new TemplateVariantInputFrameResponse(
                            $frame->x,
                            $frame->y,
                            $frame->width,
                            $frame->height,
                        )
                        : null,
                    containerId: $containerIdByInputId[$input->inputId] ?? null,
                    textStyle: $textStyle !== null
                        ? new TemplateVariantInputTextStyleResponse(
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
     * Container definitions with the zone anchor resolved: `y` is the highest
     * designed member frame in the container's tree (direct fillable members
     * plus, for a nesting parent, its children's anchors) — the coordinate a
     * consumer draws the zone from. A container whose whole tree resolves to
     * no locatable member is omitted (it cannot reflow anything at render
     * time), and dropped containers also disappear from their parent's
     * `memberContainerIds`.
     *
     * Member ids are narrowed to the LISTED inputs: a design-hidden member
     * (the editor's per-layer eye toggle) is not fillable, is absent from
     * inputs[], and the render-time layout skips it exactly like a deleted
     * member — a consumer mirroring the reflow must not see it either.
     * Decorative image members ride the flow server-side only and are not
     * listed (they are not fillable).
     *
     * @param list<CanvasContainer> $containers
     * @param array<string, \WBoost\Web\Value\PlaceholderFrame> $frames
     * @param array<EditorTextInput> $inputs
     * @return list<TemplateVariantContainerResponse>
     */
    private function buildContainers(array $containers, array $frames, array $inputs): array
    {
        $inputIds = [];
        foreach ($inputs as $input) {
            $inputIds[$input->inputId] = true;
        }

        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] = $container;
        }

        /** @var array<string, null|float> $anchors */
        $anchors = [];
        $resolveAnchor = function (CanvasContainer $container) use (&$resolveAnchor, &$anchors, $byId, $frames): null|float {
            if (array_key_exists($container->id, $anchors)) {
                return $anchors[$container->id];
            }
            $anchors[$container->id] = null; // cycle guard

            $candidates = [];
            foreach ($container->memberInputIds as $memberInputId) {
                if (isset($frames[$memberInputId])) {
                    $candidates[] = $frames[$memberInputId]->y;
                }
            }
            foreach ($container->memberContainerIds as $childId) {
                $child = $byId[$childId] ?? null;
                if ($child === null) {
                    continue;
                }
                $childAnchor = $resolveAnchor($child);
                if ($childAnchor !== null) {
                    $candidates[] = $childAnchor;
                }
            }

            return $anchors[$container->id] = ($candidates === [] ? null : min($candidates));
        };

        $resolvable = [];
        foreach ($containers as $container) {
            if ($resolveAnchor($container) !== null) {
                $resolvable[$container->id] = true;
            }
        }

        $result = [];
        foreach ($containers as $container) {
            $y = $anchors[$container->id] ?? null;
            if ($y === null) {
                continue;
            }

            $memberInputIds = array_values(array_filter(
                $container->memberInputIds,
                static fn (string $id): bool => isset($inputIds[$id]),
            ));
            $memberContainerIds = array_values(array_filter(
                $container->memberContainerIds,
                static fn (string $id): bool => isset($resolvable[$id]),
            ));

            $result[] = new TemplateVariantContainerResponse(
                id: $container->id,
                maxHeight: $container->maxHeight,
                y: $y,
                memberInputIds: $memberInputIds,
                memberContainerIds: $memberContainerIds,
                gap: $container->gap,
                spaceAfter: $container->spaceAfter,
                nested: $container->isNestedIn($containers),
            );
        }

        return $result;
    }

    /**
     * @return list<TemplateVariantImageInputResponse>
     */
    private function buildImageInputs(TemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);
        $layerIndexes = $this->placeholderGeometry->placeholderObjectIndexesByInputId($canvas);
        $projectId = $variant->template->project->id;

        return array_values(array_map(
            function (EditorImageInput $input) use ($objects, $layerIndexes, $projectId, $variant): TemplateVariantImageInputResponse {
                $object = $objects[$input->inputId] ?? null;

                $frame = null;
                $defaultImageUrl = null;

                if ($object !== null) {
                    if ($input->isBackground) {
                        // Background slot: the frame IS the canvas — the designed
                        // object's cover-fit bbox overflows it, and a fill
                        // re-covers the whole canvas anyway.
                        $frame = new TemplateVariantImageInputFrameResponse(
                            0,
                            0,
                            $variant->dimension->width(),
                            $variant->dimension->height(),
                        );
                    } else {
                        $placeholderFrame = $this->placeholderGeometry->frameFromObject($object);
                        if ($placeholderFrame !== null) {
                            $frame = new TemplateVariantImageInputFrameResponse(
                                $placeholderFrame->x,
                                $placeholderFrame->y,
                                $placeholderFrame->width,
                                $placeholderFrame->height,
                            );
                        }
                    }

                    $defaultImageUrl = $this->defaultImageUrl($object);
                }

                return new TemplateVariantImageInputResponse(
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
                    isBackground: $input->isBackground,
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
