<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\UploaderHelper;

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

        /** @var list<SocialNetworkTemplate> $templates */
        $templates = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(SocialNetworkTemplate::class, 't')
            ->join('t.project', 'p')
            ->where('p.owner = :owner')
            ->setParameter('owner', $user->id->toString())
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            fn (SocialNetworkTemplate $template): SocialNetworkTemplateResponse => new SocialNetworkTemplateResponse(
                id: $template->id->toString(),
                name: $template->name,
                position: $template->position,
                categoryId: $template->category?->id->toString(),
                categoryName: $template->category?->name,
                createdAt: $template->createdAt,
                variants: array_values(array_map(
                    fn ($variant): SocialNetworkTemplateVariantResponse => new SocialNetworkTemplateVariantResponse(
                        id: $variant->id->toString(),
                        dimension: $variant->dimension->value,
                        width: $variant->dimension->width(),
                        height: $variant->dimension->height(),
                        previewImageUrl: $variant->previewImagePath !== null
                            ? $this->uploaderHelper->getPublicPath($variant->previewImagePath)
                            : null,
                        backgroundImageUrl: $this->uploaderHelper->getPublicPath($variant->backgroundImage),
                        exportUrl: $this->urlGenerator->generate(
                            'api_social_network_template_variant_export',
                            ['id' => $variant->id->toString()],
                            UrlGeneratorInterface::ABSOLUTE_URL,
                        ),
                        inputs: array_values(array_map(
                            fn ($input): SocialNetworkTemplateVariantInputResponse => new SocialNetworkTemplateVariantInputResponse(
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
                    ),
                    $template->variants(),
                )),
            ),
            $templates,
        );
    }

}
