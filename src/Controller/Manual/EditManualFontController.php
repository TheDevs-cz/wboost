<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\FormData\ManualFontsFormData;
use WBoost\Web\FormType\ManualFontsFormType;
use WBoost\Web\Message\Manual\EditManualFont;
use WBoost\Web\Message\Manual\EditManualFonts;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\ManualFontVoter;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\ManualFontType;

final class EditManualFontController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/edit-manual-font/{id}', name: 'edit_manual_font')]
    #[IsGranted(ManualFontVoter::EDIT, 'manualFont')]
    public function __invoke(
        ManualFont $manualFont,
        Request $request,
    ): Response {
        $manual = $manualFont->manual;
        $projectFonts = $this->getFonts->allForProject($manual->project->id);

        $data = new ManualFontsFormData();
        $data->font = $manualFont->font->id->toString();
        $data->type = $manualFont->type->value;
        $data->color = $manualFont->color;

        $form = $this->createForm(ManualFontsFormType::class, $data, [
            'project_fonts' => $projectFonts,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditManualFont(
                    $manualFont->id,
                    Uuid::fromString($data->font),
                    ManualFontType::from($data->type),
                    $data->color,
                ),
            );

            $this->addFlash('success', 'Font manuálu upraven!');

            return $this->redirectToRoute('manual_fonts', [
                'id' => $manual->id->toString(),
            ]);
        }

        return $this->render('edit_manual_font.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'manual_font' => $manualFont,
            'form' => $form,
        ]);
    }
}
