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
use WBoost\Web\Message\Flyer\CopyFlyerTemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;

final class DuplicateFlyerTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/flyer-template-variant/{variantId}/copy', name: 'copy_flyer_template_variant')]
    #[IsGranted(FlyerTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        FlyerTemplateVariant $variant,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopyFlyerTemplateVariant(
                $variant->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Varianta šablony zduplikována.');

        return $this->redirectToRoute('flyer_template_variants', [
            'templateId' => $variant->template->id,
        ]);
    }
}
