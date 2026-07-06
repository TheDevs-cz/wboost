<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\FormData\TemplateGroupFormData;
use WBoost\Web\FormType\TemplateGroupFormType;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\Query\GetCustomTemplateCategories;
use WBoost\Web\Query\GetSocialNetworkCategories;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\GroupCustomVariantSelection;
use WBoost\Web\Value\GroupSocialVariantSelection;

final class AddTemplateGroupController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetSocialNetworkCategories $getSocialNetworkCategories,
        readonly private GetCustomTemplateCategories $getCustomTemplateCategories,
    ) {
    }

    #[Route(path: '/project/{projectId}/add-template-group', name: 'add_template_group')]
    #[IsGranted(User::ROLE_DESIGNER)]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        $data = new TemplateGroupFormData();
        $form = $this->createForm(TemplateGroupFormType::class, $data, [
            'social_categories' => $this->getSocialNetworkCategories->allForProject($project->id),
            'custom_categories' => $this->getCustomTemplateCategories->allForProject($project->id),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $groupId = $this->provideIdentity->next();

            $socialVariants = [];

            foreach ($data->socialDimensions as $dimension) {
                $backgroundImage = $data->backgroundFor($dimension);
                // Guaranteed by the form callback validation.
                assert($backgroundImage !== null);

                $socialVariants[] = new GroupSocialVariantSelection($dimension, $backgroundImage);
            }

            $customVariants = [];

            foreach ($data->customDimensions as $row) {
                $backgroundImage = $row->backgroundImage ?? $data->commonBackground;
                // Guaranteed by the form callback validation.
                assert($backgroundImage !== null);

                $customVariants[] = new GroupCustomVariantSelection($row->dimension(), $backgroundImage);
            }

            assert(is_string($data->name));

            $this->bus->dispatch(
                new CreateTemplateGroup(
                    $project->id,
                    $groupId,
                    $data->name,
                    $data->socialCategory !== null ? Uuid::fromString($data->socialCategory) : null,
                    $data->customCategory !== null ? Uuid::fromString($data->customCategory) : null,
                    $socialVariants,
                    $customVariants,
                ),
            );

            $this->addFlash('success', 'Skupina šablon vytvořena!');

            return $this->redirectToRoute('template_group_editor', [
                'groupId' => $groupId,
            ]);
        }

        return $this->render('add_template_group.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
