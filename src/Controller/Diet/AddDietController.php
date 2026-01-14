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
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\DietFormData;
use WBoost\Web\FormType\DietFormType;
use WBoost\Web\Message\Diet\AddDiet;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddDietController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/project/{projectId}/diets/add', name: 'add_diet')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $data = new DietFormData();
        $form = $this->createForm(DietFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dietId = $this->provideIdentity->next();

            $this->bus->dispatch(
                new AddDiet(
                    $project->id,
                    $dietId,
                    $data->name,
                    $data->codes,
                ),
            );

            $this->addFlash('success', 'Dieta byla vytvořena.');

            return $this->redirectToRoute('diets', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('add_diet.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
