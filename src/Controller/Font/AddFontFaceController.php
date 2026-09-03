<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Font;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FontAlreadyHasFontFace;
use WBoost\Web\Exceptions\UnsupportedFontFile;
use WBoost\Web\FormData\FontFormData;
use WBoost\Web\FormType\FontFormType;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddFontFaceController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{id}/add-font-face', name: 'add_font_face')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        Request $request,
        Project $project,
    ): Response {
        $data = new FontFormData();

        $form = $this->createForm(FontFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $added = 0;

            // Every file is its own dispatch, so one refused file never
            // blocks the rest of the batch — the flashes say what happened.
            foreach ($data->fonts as $fontFile) {
                try {
                    $this->bus->dispatch(new AddFont($project->id, $fontFile));
                    $added++;
                } catch (HandlerFailedException $handlerFailedException) {
                    $previous = $handlerFailedException->getPrevious();

                    if ($previous instanceof FontAlreadyHasFontFace) {
                        $this->addFlash('warning', sprintf('Řez ze souboru „%s“ je již nahrán.', $fontFile->getClientOriginalName()));
                    } elseif ($previous instanceof UnsupportedFontFile) {
                        $this->addFlash('danger', $previous->getMessage());
                    } else {
                        throw $previous ?? $handlerFailedException;
                    }
                }
            }

            if ($added > 0) {
                $this->addFlash('success', $added === 1 ? 'Písmo nahráno.' : sprintf('Nahráno %d řezů písma.', $added));
            }

            return $this->redirectToRoute('fonts_list', [
                'id' => $project->id->toString(),
            ]);
        }

        return $this->render('add_font_face.html.twig', [
            'project' => $project,
            'form' => $form,
        ]);
    }
}
