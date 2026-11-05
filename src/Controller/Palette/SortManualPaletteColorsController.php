<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualPaletteColor;
use WBoost\Web\Message\Palette\SortManualPaletteColors;
use WBoost\Web\Services\Security\ManualVoter;

/**
 * Reorder of one manual's colours in "Barvy v manuálech" (F7 P3 — drag, or
 * the row menu's "Posunout výš / níž"). JSON `{sorted: [linkId…], _token}`;
 * logo and added colours each move only among their own places. Answers the
 * manual's colour order so the page can renumber its badges.
 */
final class SortManualPaletteColorsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/paleta/poradi', name: 'manual_palette_sort', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Manual $manual, Request $request): Response
    {
        /** @var mixed $data */
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $token = $data['_token'] ?? null;

        if (!is_string($token) || !$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $sorted = is_array($data['sorted'] ?? null) ? array_values(array_filter($data['sorted'], 'is_string')) : [];

        $this->bus->dispatch(new SortManualPaletteColors($manual->id, $sorted));

        return new JsonResponse([
            'order' => array_map(
                static fn (ManualPaletteColor $link): string => $link->id->toString(),
                $manual->paletteLinks(),
            ),
        ]);
    }
}
