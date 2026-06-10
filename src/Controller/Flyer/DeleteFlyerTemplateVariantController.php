<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Message\Flyer\DeleteFlyerTemplateVariant;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;

final class DeleteFlyerTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/flyer-template-variant/{variantId}/delete', name: 'delete_flyer_template_variant')]
    #[IsGranted(FlyerTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        FlyerTemplateVariant $variant,
    ): Response {
        $template = $variant->template;

        $this->bus->dispatch(
            new DeleteFlyerTemplateVariant(
                $variant->id,
            ),
        );

        $this->addFlash('success', 'Varianta šablony smazána!');

        return $this->redirectToRoute('flyer_template_variants', [
            'templateId' => $template->id,
        ]);
    }
}
