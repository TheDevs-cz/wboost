<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Font;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Font;
use WBoost\Web\Exceptions\FontNameTaken;
use WBoost\Web\FormData\RenameFontFormData;
use WBoost\Web\FormType\RenameFontFormType;
use WBoost\Web\Message\Font\RenameFont;
use WBoost\Web\Services\Security\FontVoter;

/**
 * "Přejmenovat" on a family card — a small page with one field, because the
 * rename rewrites every template of the project and deserves a plain
 * confirmation rather than an inline edit.
 */
final class RenameFontController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/font/{fontId}/rename', name: 'rename_font')]
    #[IsGranted(FontVoter::EDIT, 'font')]
    public function __invoke(
        #[MapEntity(id: 'fontId')]
        Font $font,
        Request $request,
    ): Response {
        $data = new RenameFontFormData();
        $data->name = $font->name;
        $form = $this->createForm(RenameFontFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = trim((string) $data->name);

            try {
                $this->bus->dispatch(new RenameFont($font->id, $name));
                $this->addFlash('success', sprintf('Písmo přejmenováno na „%s“ — odkazy v šablonách jsou přepsané.', $name));

                return $this->redirectToRoute('fonts_list', ['id' => $font->project->id->toString()]);
            } catch (HandlerFailedException $exception) {
                if (!$exception->getPrevious() instanceof FontNameTaken) {
                    throw $exception->getPrevious() ?? $exception;
                }

                $form->get('name')->addError(new \Symfony\Component\Form\FormError('Písmo s tímto názvem už v projektu je.'));
            }
        }

        return $this->render('rename_font.html.twig', [
            'project' => $font->project,
            'font' => $font,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
