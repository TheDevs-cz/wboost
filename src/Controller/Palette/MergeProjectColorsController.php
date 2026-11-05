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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\ProjectColorNotFound;
use WBoost\Web\Exceptions\ProjectColorsNotDuplicates;
use WBoost\Web\Message\Palette\MergeProjectColors;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Sloučit" (F7 P4) from the same-HEX dialog of Paleta barev: `survivor` =
 * the colour the designer keeps (radio), `colors[]` = every colour of the
 * group; the others merge into the survivor
 * ({@see \WBoost\Web\Services\Palette\ProjectPaletteWriter::mergeColors()}).
 * Colours of another HEX or another project are refused.
 */
final class MergeProjectColorsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProjectColorRepository $projectColorRepository,
    ) {
    }

    #[Route(path: '/project/{id}/paleta/sloucit', name: 'project_palette_merge', methods: ['POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $survivorId = $request->request->getString('survivor');
        $survivor = Uuid::isValid($survivorId) ? $this->projectColorRepository->get(Uuid::fromString($survivorId)) : throw new ProjectColorNotFound();

        // The survivor decides the project the losers must share (writer).
        if (!$survivor->project->id->equals($project->id)) {
            throw new ProjectColorNotFound();
        }

        $losers = [];

        foreach ($request->request->all('colors') as $color) {
            if (is_string($color) && Uuid::isValid($color) && strtolower($color) !== $survivor->id->toString()) {
                $losers[strtolower($color)] = Uuid::fromString($color);
            }
        }

        $hex = '#' . strtoupper($survivor->hex);

        try {
            $this->bus->dispatch(new MergeProjectColors($survivor->id, array_values($losers)));
            $this->addFlash('success', sprintf('Barvy %s sloučeny — v paletě zůstala jedna.', $hex));
        } catch (HandlerFailedException $exception) {
            $reason = $exception->getPrevious();

            if ($reason instanceof ProjectColorsNotDuplicates) {
                $this->addFlash('warning', 'Sloučit jde jen barvy se stejným HEX z palety tohoto projektu.');
            } elseif ($reason instanceof ProjectColorNotFound) {
                $this->addFlash('warning', 'Některá z barev už v paletě není — načtěte stránku znovu.');
            } else {
                throw $reason ?? $exception;
            }

            return $this->redirectToRoute('project_palette', ['id' => $project->id->toString()]);
        }

        return $this->redirect($this->generateUrl('project_palette', ['id' => $project->id->toString()]) . '#pal-' . $survivor->id->toString());
    }
}
