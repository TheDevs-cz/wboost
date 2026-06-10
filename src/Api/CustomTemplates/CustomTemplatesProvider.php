<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * @implements ProviderInterface<CustomTemplateResponse>
 */
final readonly class CustomTemplatesProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private UploaderHelper $uploaderHelper,
        private ProjectRepository $projectRepository,
        private CanvasPlaceholderGeometry $placeholderGeometry,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return list<CustomTemplateResponse>
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

        // Owner-only scope (matches the social-network templates endpoint).
        // A 404 for projects owned by someone else avoids leaking their existence.
        if (!$project->owner->id->equals($user->id)) {
            throw new NotFoundHttpException();
        }

        /** @var list<CustomTemplate> $templates */
        $templates = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(CustomTemplate::class, 't')
            ->where('t.project = :project')
            ->setParameter('project', $project->id->toString())
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            fn (CustomTemplate $template): CustomTemplateResponse => $this->buildTemplate($template),
            $templates,
        );
    }

    private function buildTemplate(CustomTemplate $template): CustomTemplateResponse
    {
        return new CustomTemplateResponse(
            id: $template->id->toString(),
            name: $template->name,
            position: $template->position,
            categoryId: $template->category?->id->toString(),
            categoryName: $template->category?->name,
            createdAt: $template->createdAt,
            variants: array_values(array_map(
                fn (CustomTemplateVariant $variant): CustomTemplateVariantResponse => $this->buildVariant($variant),
                $template->variants(),
            )),
        );
    }

    private function buildVariant(CustomTemplateVariant $variant): CustomTemplateVariantResponse
    {
        return new CustomTemplateVariantResponse(
            id: $variant->id->toString(),
            dimension: $variant->dimension->label(),
            unit: $variant->dimension->unit->value,
            unitWidth: $variant->dimension->unitWidth,
            unitHeight: $variant->dimension->unitHeight,
            width: $variant->dimension->width(),
            height: $variant->dimension->height(),
            previewImageUrl: $variant->previewImagePath !== null
                ? $this->uploaderHelper->getPublicPath($variant->previewImagePath)
                : null,
            backgroundImageUrl: $this->uploaderHelper->getPublicPath($variant->backgroundImage),
            thumbnailUrl: $this->urlGenerator->generate(
                'api_custom_template_variant_thumbnail',
                ['variantId' => $variant->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            exportUrl: $this->urlGenerator->generate(
                'api_custom_template_variant_export',
                ['id' => $variant->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            inputs: array_values(array_map(
                fn (EditorTextInput $input): CustomTemplateVariantInputResponse => new CustomTemplateVariantInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    maxLength: $input->maxLength,
                    locked: $input->locked,
                    uppercase: $input->uppercase,
                    description: $input->description,
                    hidable: $input->hidable,
                ),
                $variant->inputs,
            )),
            imageInputs: $this->buildImageInputs($variant),
        );
    }

    /**
     * @return list<CustomTemplateVariantImageInputResponse>
     */
    private function buildImageInputs(CustomTemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);

        return array_values(array_map(
            function (EditorImageInput $input) use ($objects): CustomTemplateVariantImageInputResponse {
                $object = $objects[$input->inputId] ?? null;

                $frame = null;
                $defaultImageUrl = null;

                if ($object !== null) {
                    $placeholderFrame = $this->placeholderGeometry->frameFromObject($object);
                    if ($placeholderFrame !== null) {
                        $frame = new CustomTemplateVariantImageInputFrameResponse(
                            $placeholderFrame->x,
                            $placeholderFrame->y,
                            $placeholderFrame->width,
                            $placeholderFrame->height,
                        );
                    }

                    $defaultImageUrl = $this->defaultImageUrl($object);
                }

                return new CustomTemplateVariantImageInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    description: $input->description,
                    allowMove: $input->allowMove,
                    allowResize: $input->allowResize,
                    allowRotate: $input->allowRotate,
                    hidable: $input->hidable,
                    allowedDirectoryIds: $input->allowedDirectoryIds,
                    frame: $frame,
                    defaultImageUrl: $defaultImageUrl,
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
