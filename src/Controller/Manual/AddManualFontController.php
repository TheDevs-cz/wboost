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
use WBoost\Web\Message\Manual\AddManualFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\ManualFontType;

final class AddManualFontController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/add-font', name: 'add_manual_font')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        Request $request,
        Manual $manual,
    ): Response {
        $projectFonts = $this->getFonts->allForProject($manual->project->id);
        $data = new ManualFontsFormData();

        $form = $this->createForm(ManualFontsFormType::class, $data, [
            'project_fonts' => $projectFonts,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            assert($data->font !== null);
            assert($data->type !== null);

            $this->bus->dispatch(
                new AddManualFont(
                    $manual->id,
                    Uuid::fromString($data->font),
                    ManualFontType::from($data->type),
                    $data->color,
                ),
            );

            $this->addFlash('success', 'Přidán font do manuálu!');

            return $this->redirectToRoute('manual_fonts', [
                'id' => $manual->id->toString(),
            ]);
        }

        return $this->render('add_manual_font.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'form' => $form,
        ]);
    }
}
