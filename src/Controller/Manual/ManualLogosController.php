<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\ManualImagesFormData;
use WBoost\Web\FormType\ManualImagesFormType;
use WBoost\Web\Message\Manual\UpdateManualImages;
use WBoost\Web\Services\Security\ManualVoter;

final class ManualLogosController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/logos', name: 'manual_logos')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        Request $request,
        Manual $manual,
    ): Response {
        $data = new ManualImagesFormData();

        $form = $this->createForm(ManualImagesFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                UpdateManualImages::fromFormData($manual->id, $data),
            );

            return $this->redirectToRoute('manual_logos', [
                'id' => $manual->id->toString(),
            ]);
        }

        return $this->render('manual_logos.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'form' => $form,
        ]);
    }
}
