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
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Message\Palette\SortProjectColors;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * Reorder inside one group of Paleta barev (F7 P2 — drag, or the card menu's
 * "Posunout výš / níž"). JSON `{sorted: [colorId…], _token}`; answers the
 * whole palette order so the page can re-sort its strip preview.
 */
final class SortProjectColorsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProjectColorRepository $projectColorRepository,
    ) {
    }

    #[Route(path: '/project/{id}/paleta/poradi', name: 'project_palette_sort', methods: ['POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(Project $project, Request $request): Response
    {
        /** @var mixed $data */
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $token = $data['_token'] ?? null;

        if (!is_string($token) || !$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $sorted = is_array($data['sorted'] ?? null) ? array_values(array_filter($data['sorted'], 'is_string')) : [];

        $this->bus->dispatch(new SortProjectColors($project->id, $sorted));

        return new JsonResponse([
            'order' => array_map(
                static fn (ProjectColor $color): string => $color->id->toString(),
                $this->projectColorRepository->forProject($project->id),
            ),
        ]);
    }
}
