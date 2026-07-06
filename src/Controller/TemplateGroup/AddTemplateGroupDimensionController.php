<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\FormData\TemplateGroupDimensionFormData;
use WBoost\Web\FormType\TemplateGroupDimensionFormType;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupCustomDimension;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupSocialDimension;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TemplateGroupVoter;

final class AddTemplateGroupDimensionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/add-dimension', name: 'add_template_group_dimension')]
    #[IsGranted(TemplateGroupVoter::EDIT, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
        Request $request,
    ): Response {
        $data = new TemplateGroupDimensionFormData();
        $form = $this->createForm(TemplateGroupDimensionFormType::class, $data);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variantId = $this->provideIdentity->next();
            $backgroundImage = $data->backgroundImage;
            assert($backgroundImage !== null);

            if ($data->module === TemplateGroupDimensionFormData::MODULE_SOCIAL) {
                $dimension = $data->socialDimension;
                assert($dimension !== null);

                $this->bus->dispatch(
                    new AddTemplateGroupSocialDimension(
                        $group->id,
                        $variantId,
                        $dimension,
                        $backgroundImage,
                    ),
                );
            } else {
                $this->bus->dispatch(
                    new AddTemplateGroupCustomDimension(
                        $group->id,
                        $variantId,
                        $data->customDimension(),
                        $backgroundImage,
                    ),
                );
            }

            $this->addFlash('success', 'Rozměr přidán do skupiny!');

            return $this->redirectToRoute('template_group_editor', [
                'groupId' => $group->id,
            ]);
        }

        return $this->render('add_template_group_dimension.html.twig', [
            'form' => $form,
            'project' => $group->project,
            'group' => $group,
        ]);
    }
}
