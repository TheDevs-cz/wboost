<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\FormData\CustomTemplateVariantFormData;
use WBoost\Web\FormType\CustomTemplateVariantFormType;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplateVariant;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Services\Security\CustomTemplateVariantVoter;
use WBoost\Web\Services\UploaderHelper;

final class EditCustomTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private CustomTemplateVariantRepository $variantRepository,
        readonly private MessageBusInterface $bus,
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(path: '/custom-template-variant/{variantId}/edit', name: 'edit_custom_template_variant')]
    #[IsGranted(CustomTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        CustomTemplateVariant $variant,
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
                new EditCustomTemplateVariant(
                    $variant->id,
                    backgroundImage: null,
                    backgroundImagePath: $backgroundImagePath,
                ),
            );

            $variant = $this->variantRepository->get($variant->id);

            return $this->json(['filePath' => $this->uploaderHelper->getPublicPath($variant->backgroundImage)]);
        }

        $data = new CustomTemplateVariantFormData();
        $form = $this->createForm(CustomTemplateVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditCustomTemplateVariant(
                    $variant->id,
                    $data->backgroundImage,
                ),
            );

            // Get fresh one
            $variant = $this->variantRepository->get($variant->id);

            return $this->json(['filePath' => $this->uploaderHelper->getPublicPath($variant->backgroundImage)]);
        }

        return $this->json(['error' => 'Invalid form submission'], 400);
    }
}
