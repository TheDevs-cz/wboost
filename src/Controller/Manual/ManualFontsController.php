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
use WBoost\Web\FormData\ManualFontsFormData;
use WBoost\Web\FormType\ManualFontsFormType;
use WBoost\Web\Message\Manual\EditManualFonts;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\ManualVoter;

final class ManualFontsController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/fonts', name: 'manual_fonts')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        Request $request,
        Manual $manual,
    ): Response {
        $projectFonts = $this->getFonts->allForProject($manual->project->id);
        $data = new ManualFontsFormData();
        $data->primaryFont = $manual->primaryFont?->id->toString();
        $data->secondaryFont = $manual->secondaryFont?->id->toString();

        $form = $this->createForm(ManualFontsFormType::class, $data, [
            'project_fonts' => $projectFonts,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditManualFonts(
                    $manual->id,
                    $data->primaryFont ? Uuid::fromString($data->primaryFont) : null,
                    $data->secondaryFont ? Uuid::fromString($data->secondaryFont) : null,
                ),
            );

            $this->addFlash('success', 'Fonty manuálu upraveny!');

            return $this->redirectToRoute('manual_fonts', [
                'id' => $manual->id->toString(),
            ]);
        }

        return $this->render('manual_fonts.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'available_fonts' => $projectFonts,
            'form' => $form,
        ]);
    }
}
