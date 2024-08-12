<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\ManualMockupPageFormData;
use WBoost\Web\FormType\ManualMockupPageFormType;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\MockupPageLayout;

final class AddManualMockupPageController extends AbstractController
{
    public function __construct(
    ) {
    }

    #[Route(path: '/manual/{id}/add-mockup-page/{mockupPageLayout}', name: 'add_manual_mockup_page')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Manual $manual,
        null|MockupPageLayout $mockupPageLayout = null,
    ): Response {

        $formData = new ManualMockupPageFormData();
        $formData->images = array_fill(0, $mockupPageLayout?->getUploadInputsCount() ?? 0, null);
        $form = $this->createForm(ManualMockupPageFormType::class, $formData);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
        }

        return $this->render('add_manual_mockup_page.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'layouts' => MockupPageLayout::cases(),
            'selected_layout' => $mockupPageLayout,
            'form' => $form,
        ]);
    }
}
