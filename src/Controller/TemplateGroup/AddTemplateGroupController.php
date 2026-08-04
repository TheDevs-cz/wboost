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
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\FormData\TemplateVariantFormData;
use WBoost\Web\FormData\TemplateGroupFormData;
use WBoost\Web\FormType\TemplateGroupFormType;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddTemplateGroupController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetTemplateCategories $getTemplateCategories,
        readonly private TemplateVariantRepository $variantRepository,
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
            $sourceVariantId = $request->query->getString('sourceVariantId');

            if ($sourceVariantId !== '') {
                $sourceVariant = $this->resolveSourceVariant($project, $sourceVariantId);

                $data->sourceVariantId = $sourceVariantId;
                $data->name = $sourceVariant->template->name;

                if ($sourceVariant->dimension->preset !== null) {
                    $data->presetDimensions = [$sourceVariant->dimension->preset];
                } else {
                    $row = new TemplateVariantFormData();
                    $row->unit = $sourceVariant->dimension->unit;
                    $row->width = $sourceVariant->dimension->unitWidth;
                    $row->height = $sourceVariant->dimension->unitHeight;

                    $data->customDimensions = [$row];
                }
            }
        }

        $form = $this->createForm(TemplateGroupFormType::class, $data, [
            'categories' => $this->getTemplateCategories->allForProject($project->id),
        ]);

        $form->handleRequest($request);

        // On submit the source travels in a hidden field — re-resolve it so a
        // tampered id (foreign project) 404s before dispatch, and the banner
        // survives a validation re-render.
        if ($form->isSubmitted() && $data->hasDesignSource()) {
            $sourceVariant = $this->resolveSourceVariant($project, (string) $data->sourceVariantId);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $groupId = $this->provideIdentity->next();

            assert(is_string($data->name));

            $this->bus->dispatch(
                new CreateTemplateGroup(
                    $project->id,
                    $groupId,
                    $data->name,
                    $data->category !== null ? Uuid::fromString($data->category) : null,
                    $data->variantSelections(),
                    $sourceVariant?->id,
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
        ]);
    }

    private function resolveSourceVariant(
        Project $project,
        string $variantId,
    ): TemplateVariant {
        if (!Uuid::isValid($variantId)) {
            throw $this->createNotFoundException();
        }

        try {
            $variant = $this->variantRepository->get(Uuid::fromString($variantId));
        } catch (TemplateVariantNotFound) {
            throw $this->createNotFoundException();
        }

        if (!$variant->template->project->id->equals($project->id)) {
            throw $this->createNotFoundException();
        }

        return $variant;
    }
}
