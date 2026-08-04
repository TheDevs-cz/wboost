<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Message\Template\DeleteTemplateVariant;
use WBoost\Web\Services\Security\TemplateVariantVoter;

final class DeleteTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/delete', name: 'delete_template_variant')]
    #[IsGranted(TemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
    ): Response {
        $template = $variant->template;

        $this->bus->dispatch(
            new DeleteTemplateVariant(
                $variant->id,
            ),
        );

        $this->addFlash('success', 'Varianta šablony smazána!');

        return $this->redirectToRoute('template_variants', [
            'templateId' => $template->id,
        ]);
    }
}
