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
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use WBoost\Web\Exceptions\SocialNetworkTemplateVariantNotFound;
use WBoost\Web\FormData\CustomTemplateVariantFormData;
use WBoost\Web\FormData\TemplateGroupFormData;
use WBoost\Web\FormType\TemplateGroupFormType;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\Query\GetCustomTemplateCategories;
use WBoost\Web\Query\GetSocialNetworkCategories;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
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
        readonly private SocialNetworkTemplateVariantRepository $socialVariantRepository,
        readonly private CustomTemplateVariantRepository $customVariantRepository,
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
        $sourceVariant = null;

        // "Create from existing template": the picker page links here with the
        // design source in the query string — prefill the wizard from it.
        if (!$request->isMethod('POST')) {
            $sourceModule = $request->query->getString('sourceModule');
            $sourceVariantId = $request->query->getString('sourceVariantId');

            if ($sourceModule !== '' && $sourceVariantId !== '') {
                $sourceVariant = $this->resolveSourceVariant($project, $sourceModule, $sourceVariantId);

                $data->sourceModule = $sourceModule;
                $data->sourceVariantId = $sourceVariantId;
                $data->name = $sourceVariant->template->name;

                if ($sourceVariant instanceof SocialNetworkTemplateVariant) {
                    $data->socialDimensions = [$sourceVariant->dimension];
                } else {
                    $row = new CustomTemplateVariantFormData();
                    $row->unit = $sourceVariant->dimension->unit;
                    $row->width = $sourceVariant->dimension->unitWidth;
                    $row->height = $sourceVariant->dimension->unitHeight;

                    $data->customDimensions = [$row];
                }
            }
        }

        $form = $this->createForm(TemplateGroupFormType::class, $data, [
            'social_categories' => $this->getSocialNetworkCategories->allForProject($project->id),
            'custom_categories' => $this->getCustomTemplateCategories->allForProject($project->id),
        ]);

        $form->handleRequest($request);

        // On submit the source travels in hidden fields — re-resolve it so a
        // tampered id (foreign project, wrong module) 404s before dispatch,
        // and the banner survives a validation re-render.
        if ($form->isSubmitted() && $data->hasDesignSource()) {
            $sourceVariant = $this->resolveSourceVariant(
                $project,
                (string) $data->sourceModule,
                (string) $data->sourceVariantId,
            );
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $groupId = $this->provideIdentity->next();

            $socialVariants = [];

            foreach ($data->socialDimensions as $dimension) {
                $backgroundImage = $data->backgroundFor($dimension);
                // Guaranteed by the form callback validation.
                assert($backgroundImage !== null || $sourceVariant !== null);

                $socialVariants[] = new GroupSocialVariantSelection($dimension, $backgroundImage);
            }

            $customVariants = [];

            foreach ($data->customDimensions as $row) {
                $backgroundImage = $row->backgroundImage ?? $data->commonBackground;
                // Guaranteed by the form callback validation.
                assert($backgroundImage !== null || $sourceVariant !== null);

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
                    $sourceVariant instanceof SocialNetworkTemplateVariant ? $sourceVariant->id : null,
                    $sourceVariant instanceof CustomTemplateVariant ? $sourceVariant->id : null,
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
            'source_variant' => $sourceVariant,
            'source_module' => match (true) {
                $sourceVariant instanceof SocialNetworkTemplateVariant => 'social',
                $sourceVariant instanceof CustomTemplateVariant => 'custom',
                default => null,
            },
        ]);
    }

    private function resolveSourceVariant(
        Project $project,
        string $module,
        string $variantId,
    ): SocialNetworkTemplateVariant|CustomTemplateVariant {
        if (!in_array($module, ['social', 'custom'], true) || !Uuid::isValid($variantId)) {
            throw $this->createNotFoundException();
        }

        try {
            $variant = $module === 'social'
                ? $this->socialVariantRepository->get(Uuid::fromString($variantId))
                : $this->customVariantRepository->get(Uuid::fromString($variantId));
        } catch (SocialNetworkTemplateVariantNotFound | CustomTemplateVariantNotFound) {
            throw $this->createNotFoundException();
        }

        if (!$variant->template->project->id->equals($project->id)) {
            throw $this->createNotFoundException();
        }

        return $variant;
    }
}
