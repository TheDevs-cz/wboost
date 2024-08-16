<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\FormData\ManualMockupPageFormData;
use WBoost\Web\FormType\ManualMockupPageFormType;
use WBoost\Web\Message\Manual\EditManualMockupPage;
use WBoost\Web\Services\Security\ManualMockupPageVoter;

final class EditManualMockupPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/edit-manual-mockup-page/{id}', name: 'edit_manual_mockup_page')]
    #[IsGranted(ManualMockupPageVoter::EDIT, 'page')]
    public function __invoke(
        ManualMockupPage $page,
        Request $request,
    ): Response
    {
        $formData = new ManualMockupPageFormData($page->layout);
        $formData->name = $page->name;
        $form = $this->createForm(ManualMockupPageFormType::class, $formData);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditManualMockupPage(
                    $page->id,
                    $formData->name,
                    $formData->images,
                ),
            );

            $this->addFlash('success', 'Stránka s mockupy upravena!');

            return $this->redirectToRoute('manual_mockup_pages', [
                'id' => $page->manual->id,
            ]);
        }

        return $this->render('edit_manual_mockup_page.html.twig', [
            'project' => $page->manual->project,
            'manual' => $page->manual,
            'mockup_page' => $page,
            'form' => $form,
        ]);
    }
}
