<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\FormData\SocialNetworkEditorFormData;
use WBoost\Web\FormType\SocialNetworkEditorFormType;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\SocialNetworkTemplateVoter;

final class SocialNetworkTemplateEditorController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
    ) {
    }

    #[Route(path: '/social-network-template/{templateId}/editor', name: 'social_network_editor')]
    #[IsGranted(SocialNetworkTemplateVoter::VIEW, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        SocialNetworkTemplate $template,
        Request $request,
    ): Response {
        $formData = new SocialNetworkEditorFormData();
        $form = $this->createForm(SocialNetworkEditorFormType::class, $formData);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //

            $this->addFlash('success', 'Editor uložen!');

            return $this->redirectToRoute('social_network_editor', [
                'templateId' => $template->id,
            ]);
        }

        return $this->render('social_network_editor.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'fonts' => $this->getFonts->allForProject($template->project->id),
            'form' => $form,
        ]);
    }
}
