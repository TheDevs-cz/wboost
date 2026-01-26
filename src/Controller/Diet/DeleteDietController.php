<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Diet;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Diet;
use WBoost\Web\Message\Diet\DeleteDiet;
use WBoost\Web\Services\Security\DietVoter;

final class DeleteDietController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/diet/{dietId}/delete', name: 'delete_diet')]
    #[IsGranted(DietVoter::EDIT, 'diet')]
    public function __invoke(
        #[MapEntity(id: 'dietId')]
        Diet $diet,
    ): Response {
        $projectId = $diet->project->id;

        $this->bus->dispatch(
            new DeleteDiet($diet->id),
        );

        $this->addFlash('success', 'Dieta byla smazána.');

        return $this->redirectToRoute('diets', [
            'projectId' => $projectId,
        ]);
    }
}
