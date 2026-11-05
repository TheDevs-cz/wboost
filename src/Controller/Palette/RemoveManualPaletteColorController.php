<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ManualPaletteColorFromLogo;
use WBoost\Web\Message\Palette\RemoveManualPaletteColor;
use WBoost\Web\Services\Security\ManualVoter;

/**
 * "Odebrat z manuálu" (F7 P3) — one ADDED colour leaves the manual (the
 * palette keeps it, with its role and print data, so it can be picked
 * again). A logo colour is refused: it comes from the logo (D3).
 */
final class RemoveManualPaletteColorController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/paleta/{linkId}/odebrat', name: 'manual_palette_remove', requirements: ['linkId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Manual $manual, string $linkId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->bus->dispatch(new RemoveManualPaletteColor($manual->id, Uuid::fromString($linkId)));
            $this->addFlash('success', sprintf('Barva odebrána z manuálu %s — v paletě zůstává.', $manual->name));
        } catch (HandlerFailedException $exception) {
            $reason = $exception->getPrevious();

            if (!$reason instanceof ManualPaletteColorFromLogo) {
                throw $reason ?? $exception;
            }

            $this->addFlash('warning', 'Barvu z logotypu nelze z manuálu odebrat — změní se až s novým logem.');
        }

        return $this->redirectToRoute('project_palette', [
            'id' => $manual->project->id->toString(),
            '_fragment' => ProjectPaletteController::manualAnchor($manual),
        ]);
    }
}
