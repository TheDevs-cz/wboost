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
use WBoost\Web\FormData\FontFormData;
use WBoost\Web\FormType\FontFormType;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddFontController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{id}/add-font', name: 'add_font')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        Request $request,
        Project $project,
    ): Response {
        $data = new FontFormData();

        $form = $this->createForm(FontFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            assert($data->font !== null);

            try {
                $this->bus->dispatch(
                    new AddFont(
                        $project->id,
                        $data->font,
                    ),
                );
            } catch (HandlerFailedException $handlerFailedException) {
                if ($handlerFailedException->getPrevious() instanceof FontAlreadyHasFontFace) {
                    $this->addFlash('warning', 'Tento font je již nahrán');
                } else {
                    throw $handlerFailedException->getPrevious() ?? $handlerFailedException;
                }
            }

            return $this->redirectToRoute('fonts_list', [
                'id' => $project->id->toString(),
            ]);
        }

        return $this->render('add_font.html.twig', [
            'project' => $project,
            'form' => $form,
        ]);
    }
}
