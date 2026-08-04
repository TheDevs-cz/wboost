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
use WBoost\Web\Message\Template\CopyTemplateVariant;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TemplateVariantVoter;

final class DuplicateTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/copy', name: 'copy_template_variant')]
    #[IsGranted(TemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopyTemplateVariant(
                $variant->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Varianta šablony zduplikována.');

        return $this->redirectToRoute('template_variants', [
            'templateId' => $variant->template->id,
        ]);
    }
}
