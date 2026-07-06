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
use WBoost\Web\Value\MockupPageLayout;

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
        $formData = new ManualMockupPageFormData();
        $formData->name = $page->name;
        $formData->layout = $page->layout;
        $form = $this->createForm(ManualMockupPageFormType::class, $formData);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditManualMockupPage(
                    $page->id,
                    $formData->name,
                    array_slice($formData->images, 0, $page->layout->uploadInputsCount()),
                    array_map(
                        static fn (string $flag): bool => $flag === '1',
                        array_slice($formData->removeImages, 0, $page->layout->uploadInputsCount()),
                    ),
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
            'layouts_geometry' => MockupPageLayout::exportGeometry(),
            'form' => $form,
        ]);
    }
}
