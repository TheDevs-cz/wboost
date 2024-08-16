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
use WBoost\Web\FormData\ColorMappingFormData;
use WBoost\Web\FormType\ColorMappingFormType;
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
        $data = new ColorMappingFormData();
        $data->c1 = $manual->color1;
        $data->c2 = $manual->color2;
        $data->c3 = $manual->color3;
        $data->c4 = $manual->color4;
        $data->secondaryColors = $manual->secondaryColors;

        $form = $this->createForm(ColorMappingFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, string> $mapping */
            $mapping = $form->getExtraData()['mapping'] ?? [];

            $this->bus->dispatch(
                new EditManualColors(
                    $manual->id,
                    $data->c1,
                    $data->c2,
                    $data->c3,
                    $data->c4,
                    $mapping,
                    $data->secondaryColors,
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
        ]);
    }
}
