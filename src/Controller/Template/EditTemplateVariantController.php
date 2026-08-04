<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\FormData\TemplateVariantFormData;
use WBoost\Web\FormType\TemplateVariantFormType;
use WBoost\Web\Message\Template\EditTemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\UploaderHelper;

final class EditTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private TemplateVariantRepository $variantRepository,
        readonly private MessageBusInterface $bus,
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/edit', name: 'edit_template_variant')]
    #[IsGranted(TemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        Request $request,
    ): Response {
        // The editor's "Pozadí" button picks an asset from the project image
        // gallery; the orchestrator posts the chosen path as a
        // `backgroundImagePath` form field, bypassing the file-upload form
        // entirely. The file-upload form path is still accepted for any caller
        // posting raw uploads.
        $backgroundImagePath = $request->request->get('backgroundImagePath');

        if (is_string($backgroundImagePath) && $backgroundImagePath !== '') {
            $this->bus->dispatch(
                new EditTemplateVariant(
                    $variant->id,
                    backgroundImage: null,
                    backgroundImagePath: $backgroundImagePath,
                ),
            );

            $variant = $this->variantRepository->get($variant->id);

            return $this->json(['filePath' => $variant->backgroundImage !== null ? $this->uploaderHelper->getPublicPath($variant->backgroundImage) : null]);
        }

        $data = new TemplateVariantFormData();
        $form = $this->createForm(TemplateVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditTemplateVariant(
                    $variant->id,
                    $data->backgroundImage,
                ),
            );

            // Get fresh one
            $variant = $this->variantRepository->get($variant->id);

            return $this->json(['filePath' => $variant->backgroundImage !== null ? $this->uploaderHelper->getPublicPath($variant->backgroundImage) : null]);
        }

        return $this->json(['error' => 'Invalid form submission'], 400);
    }
}
