<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Exceptions\ProjectColorInLogo;
use WBoost\Web\Exceptions\ProjectColorUsedInManuals;
use WBoost\Web\Message\Palette\DeleteProjectColor;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Smazat barvu" (F7 P2) from the card's confirm dialog. The guard lives in
 * `ProjectPaletteWriter::deleteColor()`: a logo colour is refused ("změní se
 * až s novým logem"), a colour manuals added needs `removeFromManuals=1`
 * (the dialog's "Odebrat z manuálů a smazat"), an unused one just goes.
 * Template colour allowlists keep their HEX snapshots (D4).
 */
final class DeleteProjectColorController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/paleta/barva/{colorId}/smazat', name: 'project_palette_color_delete', methods: ['POST'])]
    public function __invoke(
        #[MapEntity(id: 'colorId')]
        ProjectColor $color,
        Request $request,
    ): Response {
        $project = $color->project;
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        if (!$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $hex = '#' . strtoupper($color->hex);

        try {
            $this->bus->dispatch(new DeleteProjectColor($color->id, $request->request->getBoolean('removeFromManuals')));
            $this->addFlash('success', sprintf('Barva %s smazána.', $hex));
        } catch (HandlerFailedException $exception) {
            $reason = $exception->getPrevious();

            if ($reason instanceof ProjectColorInLogo) {
                $this->addFlash('warning', sprintf('Barvu %s nelze smazat — je v logotypu manuálu a změní se až s novým logem.', $hex));
            } elseif ($reason instanceof ProjectColorUsedInManuals) {
                $this->addFlash('warning', sprintf('Barvu %s používají manuály — nejdřív ji z nich odeberte.', $hex));
            } else {
                throw $reason ?? $exception;
            }
        }

        return $this->redirectToRoute('project_palette', ['id' => $project->id->toString()]);
    }
}
