<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Diet;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Diet;
use WBoost\Web\FormData\DietFormData;
use WBoost\Web\FormType\DietFormType;
use WBoost\Web\Message\Diet\EditDiet;
use WBoost\Web\Services\Security\DietVoter;

final class EditDietController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/diet/{dietId}/edit', name: 'edit_diet')]
    #[IsGranted(DietVoter::EDIT, 'diet')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'dietId')]
        Diet $diet,
    ): Response {
        $data = new DietFormData();
        $data->name = $diet->name;
        $data->codes = $diet->codes;

        $form = $this->createForm(DietFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditDiet(
                    $diet->id,
                    $data->name,
                    $data->codes,
                ),
            );

            $this->addFlash('success', 'Dieta byla upravena.');

            return $this->redirectToRoute('diets', [
                'projectId' => $diet->project->id,
            ]);
        }

        return $this->render('edit_diet.html.twig', [
            'form' => $form,
            'diet' => $diet,
            'project' => $diet->project,
        ]);
    }
}
