<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\Palette\AddProjectColor;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Palette\PaletteColorInput;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Přidat barvu" (F7 P2): appends a colour to the palette with a starter HEX
 * the palette does not have yet (the prototype's picks) and lands on its
 * card with the HEX focused. No manual uses it until one picks it (P3), so it
 * shows as "Nepoužitá" and templates do not offer it yet
 * (`GetProjectPalette` is linked-only).
 */
final class AddProjectColorController extends AbstractController
{
    private const array STARTER_HEXES = ['1F3B5F', 'E8B04B', 'F2EFE9', '2D2D2D', '7A5CC4'];

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private ProjectColorRepository $projectColorRepository,
    ) {
    }

    #[Route(path: '/project/{id}/paleta/pridat', name: 'project_palette_add', methods: ['POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $used = [];
        foreach ($this->projectColorRepository->forProject($project->id) as $color) {
            $used[$color->hexKey] = true;
        }

        $hex = '808080';
        foreach (self::STARTER_HEXES as $candidate) {
            if (!isset($used[PaletteColorInput::hexKey($candidate)])) {
                $hex = $candidate;
                break;
            }
        }

        $colorId = $this->provideIdentity->next();
        $this->bus->dispatch(new AddProjectColor($colorId, $project->id, $hex));

        $this->addFlash('success', 'Barva přidána — upravte její HEX.');

        return $this->redirect($this->generateUrl('project_palette', ['id' => $project->id->toString(), 'nova' => $colorId->toString()]) . '#pal-' . $colorId->toString());
    }
}
