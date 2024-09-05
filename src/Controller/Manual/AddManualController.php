<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\ManualFormData;
use WBoost\Web\FormType\ManualFormType;
use WBoost\Web\Message\Manual\AddManual;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddManualController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/project/{id}/add-manual', name: 'add_manual')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $data = new ManualFormData();
        $form = $this->createForm(ManualFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $manualId = $this->provideIdentity->next();

            assert($data->type !== null);

            $this->bus->dispatch(
                new AddManual(
                    $manualId,
                    $project->id,
                    $data->type,
                    $data->name,
                    $data->introImage,
                ),
            );

            return $this->redirectToRoute('manual_dashboard', [
                'id' => $manualId,
            ]);
        }

        return $this->render('add_manual.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
