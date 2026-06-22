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
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

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

        // Owner-only scope (matches the previous endpoint). A 404 for projects
        // owned by someone else avoids leaking their existence.
        if (!$project->owner->id->equals($user->id)) {
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
            inputs: $this->buildTextInputs($variant),
            imageInputs: $this->buildImageInputs($variant),
        );
    }

    /**
     * @return list<SocialNetworkTemplateVariantInputResponse>
     */
    private function buildTextInputs(SocialNetworkTemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);

        return array_values(array_map(
            function (EditorTextInput $input) use ($frames): SocialNetworkTemplateVariantInputResponse {
                $frame = $frames[$input->inputId] ?? null;

                return new SocialNetworkTemplateVariantInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    maxLength: $input->maxLength,
                    locked: $input->locked,
                    uppercase: $input->uppercase,
                    description: $input->description,
                    hidable: $input->hidable,
                    frame: $frame !== null
                        ? new SocialNetworkTemplateVariantInputFrameResponse(
                            $frame->x,
                            $frame->y,
                            $frame->width,
                            $frame->height,
                        )
                        : null,
                );
            },
            $variant->inputs,
        ));
    }

    /**
     * @return list<SocialNetworkTemplateVariantImageInputResponse>
     */
    private function buildImageInputs(SocialNetworkTemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);
        $projectId = $variant->template->project->id;

        return array_values(array_map(
            function (EditorImageInput $input) use ($objects, $projectId): SocialNetworkTemplateVariantImageInputResponse {
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
