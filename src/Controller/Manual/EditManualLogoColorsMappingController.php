<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\LogoColorsFormData;
use WBoost\Web\Message\Manual\EditManualLogoColorsMapping;
use WBoost\Web\FormData\LogoColorsEditForm;
use WBoost\Web\Services\Manual\ManualEditForms;
use WBoost\Web\Services\Manual\ManualPreviewTurboStream;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;

/**
 * Saves "Mapování barev loga" of one logo type × colour variant (the pencil
 * on a manual-preview logo card, `LogoColorsMappingModal` component). From the
 * preview the form is Turbo-enabled: the answer is a Turbo Stream replacing
 * that card (background, recoloured logo, download links) in place, or the
 * modal body with its validation errors. A plain POST — no JS — is redirected
 * back to the card.
 */
final class EditManualLogoColorsMappingController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ManualEditForms $forms,
        readonly private ManualPreviewTurboStream $turboStream,
    ) {
    }

    #[Route(
        path: '/manual/{manualId}/mapovani-barev-loga/{logoVariant}/{colorVariant}',
        name: 'manual_logo_colors_mapping_edit',
        methods: ['POST'],
    )]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        LogoTypeVariant $logoVariant,
        LogoColorVariant $colorVariant,
        Request $request,
    ): Response {
        if ($manual->logo->variant($logoVariant) === null) {
            throw new NotFoundHttpException('The manual has no image for this logo variant.');
        }

        $edit = $this->forms->logoColors($manual, $logoVariant, $colorVariant);
        $form = $edit->form;
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var LogoColorsFormData $data */
            $data = $form->getData();

            $this->bus->dispatch(new EditManualLogoColorsMapping(
                $manual->id,
                $logoVariant,
                $colorVariant,
                $data->background,
                $data->colors,
                array_filter($data->opacities, is_int(...)),
            ));

            if (ManualPreviewTurboStream::wants($request)) {
                return $this->turboStream->logoColorsSaved($request, $manual, $logoVariant, $colorVariant);
            }
        } elseif (ManualPreviewTurboStream::wants($request)) {
            return $this->turboStream->logoColorsInvalid($request, $edit);
        }

        return $this->redirect($this->generateUrl('manual_preview', [
            'projectSlug' => $manual->project->slug,
            'manualSlug' => $manual->slug,
        ]) . '#' . LogoColorsEditForm::cardId($logoVariant, $colorVariant));
    }
}
