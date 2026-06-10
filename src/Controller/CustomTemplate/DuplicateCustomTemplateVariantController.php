<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Message\CustomTemplate\CopyCustomTemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\CustomTemplateVariantVoter;

final class DuplicateCustomTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/custom-template-variant/{variantId}/copy', name: 'copy_custom_template_variant')]
    #[IsGranted(CustomTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        CustomTemplateVariant $variant,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopyCustomTemplateVariant(
                $variant->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Varianta šablony zduplikována.');

        return $this->redirectToRoute('custom_template_variants', [
            'templateId' => $variant->template->id,
        ]);
    }
}
