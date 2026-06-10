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
use WBoost\Web\Message\CustomTemplate\DeleteCustomTemplateVariant;
use WBoost\Web\Services\Security\CustomTemplateVariantVoter;

final class DeleteCustomTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/custom-template-variant/{variantId}/delete', name: 'delete_custom_template_variant')]
    #[IsGranted(CustomTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        CustomTemplateVariant $variant,
    ): Response {
        $template = $variant->template;

        $this->bus->dispatch(
            new DeleteCustomTemplateVariant(
                $variant->id,
            ),
        );

        $this->addFlash('success', 'Varianta šablony smazána!');

        return $this->redirectToRoute('custom_template_variants', [
            'templateId' => $template->id,
        ]);
    }
}
