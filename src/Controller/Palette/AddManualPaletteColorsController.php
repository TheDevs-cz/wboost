<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Palette\AddManualPaletteColors;
use WBoost\Web\Services\Security\ManualVoter;

/**
 * "Přidat z palety" (F7 P3) — the dialog of one manual's section in the
 * "Barvy v manuálech" box posts the picked palette colours (`colors[]`);
 * they are appended to the manual's colours and become template swatches.
 * Back to that manual's section of Paleta barev.
 */
final class AddManualPaletteColorsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/paleta/pridat', name: 'manual_palette_add', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Manual $manual, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $colorIds = array_values(array_filter($request->request->all('colors'), 'is_string'));
        $before = $manual->colorsCount();

        if ($colorIds !== []) {
            $this->bus->dispatch(new AddManualPaletteColors($manual->id, $colorIds));
        }

        $added = $manual->colorsCount() - $before;

        if ($added > 0) {
            $this->addFlash('success', sprintf('Do manuálu %s %s.', $manual->name, match (true) {
                $added === 1 => 'přibyla 1 barva',
                $added < 5 => sprintf('přibyly %d barvy', $added),
                default => sprintf('přibylo %d barev', $added),
            }));
        } else {
            $this->addFlash('warning', 'Vyberte barvy palety, které chcete do manuálu přidat.');
        }

        return $this->redirectToRoute('project_palette', [
            'id' => $manual->project->id->toString(),
            '_fragment' => ProjectPaletteController::manualAnchor($manual),
        ]);
    }
}
