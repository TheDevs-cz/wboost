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
use WBoost\Web\FormData\ManualFormData;
use WBoost\Web\FormType\ManualFormType;
use WBoost\Web\Message\Manual\EditManual;
use WBoost\Web\Services\Security\ManualVoter;

final class EditManualController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,  
    ) {
    }
    
    #[Route(path: '/edit-manual/{id}', name: 'edit_manual')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Request $request, Manual $manual): Response
    {
        $data = new ManualFormData();
        $data->name = $manual->name;
        $data->type = $manual->type;

        $form = $this->createForm(ManualFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditManual(
                    $manual->id,
                    $data->type,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Manuál upraven!');

            return $this->redirectToRoute('manual_dashboard', [
                'id' => $manual->id->toString(),
            ]);
        }

        return $this->render('edit_manual.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'form' => $form,
        ]);
    }
}
