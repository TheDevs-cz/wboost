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
use WBoost\Web\FormData\ManualColorsFormData;
use WBoost\Web\FormType\ManualColorsFormType;
use WBoost\Web\Message\Manual\EditManualColors;
use WBoost\Web\Services\Security\ManualVoter;

final class ManualColorsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/colors', name: 'manual_colors')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Request $request, Manual $manual): Response
    {
        $data = ManualColorsFormData::fromManual($manual);

        $form = $this->createForm(ManualColorsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditManualColors(
                    $manual->id,
                    $data->manualDetectedColors(),
                    $data->manualCustomColors(),
                ),
            );

            $this->addFlash('success', 'Barvy manuálu uloženy!');

            return $this->redirectToRoute('manual_colors', [
                'id' => $manual->id->toString(),
            ]);
        }

        return $this->render('manual_colors.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'form' => $form,
            'detected_colors' => $manual->logo->getDetectedColors(),
        ]);
    }
}
