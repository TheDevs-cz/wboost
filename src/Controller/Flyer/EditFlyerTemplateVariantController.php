<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\FormData\FlyerTemplateVariantFormData;
use WBoost\Web\FormType\FlyerTemplateVariantFormType;
use WBoost\Web\Message\Flyer\EditFlyerTemplateVariant;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;
use WBoost\Web\Services\UploaderHelper;

final class EditFlyerTemplateVariantController extends AbstractController
{
    public function __construct(
        readonly private FlyerTemplateVariantRepository $variantRepository,
        readonly private MessageBusInterface $bus,
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(path: '/flyer-template-variant/{variantId}/edit', name: 'edit_flyer_template_variant')]
    #[IsGranted(FlyerTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        FlyerTemplateVariant $variant,
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
                new EditFlyerTemplateVariant(
                    $variant->id,
                    backgroundImage: null,
                    backgroundImagePath: $backgroundImagePath,
                ),
            );

            $variant = $this->variantRepository->get($variant->id);

            return $this->json(['filePath' => $this->uploaderHelper->getPublicPath($variant->backgroundImage)]);
        }

        $data = new FlyerTemplateVariantFormData();
        $form = $this->createForm(FlyerTemplateVariantFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditFlyerTemplateVariant(
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
