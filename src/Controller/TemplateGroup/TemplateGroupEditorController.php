<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplateVariantCanvasEditor;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplateVariantCanvasEditor;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;

final class TemplateGroupEditorController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private GetTemplateGroupMembers $members,
        readonly private MessageBusInterface $bus,
        readonly private ClockInterface $clock,
        readonly private FileDirectoryRepository $fileDirectoryRepository,
    ) {
    }

    #[Route(path: '/template-group/{groupId}/editor', name: 'template_group_editor')]
    #[IsGranted(TemplateGroupVoter::EDIT, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
        Request $request,
    ): Response {
        $socialVariants = $this->members->socialVariants($group->id);
        $customVariants = $this->members->customVariants($group->id);

        if ($request->isMethod('POST')) {
            return $this->save($request, $socialVariants, $customVariants);
        }

        $fonts = $this->getFonts->allForProject($group->project->id);
        $fontFaceNames = [];
        foreach ($fonts as $font) {
            foreach ($font->faces as $fontFace) {
                $fontFaceNames[] = "$font->name ($fontFace->name)";
            }
        }

        $galleryDirectories = array_map(
            static fn (FileDirectory $directory): array => [
                'id' => $directory->id->toString(),
                'name' => $directory->name,
            ],
            $this->fileDirectoryRepository->listAll($group->project->id, FileSource::ProjectImage),
        );

        $variants = [];

        foreach ($socialVariants as $variant) {
            $variants[] = [
                'variant' => $variant,
                'module' => 'social',
                'module_label' => 'Sociální sítě',
                'dimension_label' => sprintf('%s (%dx%dpx)', $variant->dimension->value, $variant->dimension->width(), $variant->dimension->height()),
                'edit_variant_url' => $this->generateUrl('edit_social_network_template_variant', ['variantId' => $variant->id]),
                'export_url' => $this->generateUrl('social_network_template_variant_export', ['variantId' => $variant->id]),
            ];
        }

        foreach ($customVariants as $variant) {
            $variants[] = [
                'variant' => $variant,
                'module' => 'custom',
                'module_label' => 'Šablony',
                'dimension_label' => sprintf('%s (%dx%dpx)', $variant->dimension->label(), $variant->dimension->width(), $variant->dimension->height()),
                'edit_variant_url' => $this->generateUrl('edit_custom_template_variant', ['variantId' => $variant->id]),
                'export_url' => $this->generateUrl('custom_template_variant_export', ['variantId' => $variant->id]),
            ];
        }

        return $this->render('template_group_editor.html.twig', [
            'project' => $group->project,
            'group' => $group,
            'fonts' => $fonts,
            'font_faces' => $fontFaceNames,
            'gallery_directories' => $galleryDirectories,
            'menu_item' => 'template_groups',
            'variants' => $variants,
            'save_url' => $this->generateUrl('template_group_editor', ['groupId' => $group->id]),
        ]);
    }

    /**
     * Save contract: `variants[<variantUuid>][canvas|textInputs|imageInputs|imagePreview]`,
     * one entry per included variant, same field semantics as the single-variant
     * editor form. Validates ALL entries before dispatching anything.
     *
     * @param list<SocialNetworkTemplateVariant> $socialVariants
     * @param list<CustomTemplateVariant> $customVariants
     */
    private function save(Request $request, array $socialVariants, array $customVariants): Response
    {
        $token = $request->request->getString('_token');

        if (!$this->isCsrfTokenValid('template_group_editor', $token)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Invalid CSRF token.',
            ], Response::HTTP_BAD_REQUEST);
        }

        /** @var mixed $rawVariants */
        $rawVariants = $request->request->all()['variants'] ?? [];

        if (!is_array($rawVariants) || $rawVariants === []) {
            return $this->json([
                'status' => 'error',
                'message' => 'No variants submitted.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $socialById = [];
        foreach ($socialVariants as $variant) {
            $socialById[$variant->id->toString()] = $variant;
        }

        $customById = [];
        foreach ($customVariants as $variant) {
            $customById[$variant->id->toString()] = $variant;
        }

        $validated = [];

        foreach ($rawVariants as $variantId => $payload) {
            $variantId = (string) $variantId;

            // Only variants CREATED via the group are group-editable — a variant
            // added to a grouped template manually carries no group FK and is
            // rejected here.
            if (!isset($socialById[$variantId]) && !isset($customById[$variantId])) {
                return $this->json([
                    'status' => 'error',
                    'message' => sprintf('Variant %s does not belong to this group.', $variantId),
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!is_array($payload) || !is_string($payload['canvas'] ?? null) || !is_string($payload['textInputs'] ?? null)) {
                return $this->json([
                    'status' => 'error',
                    'message' => sprintf('Variant %s payload is incomplete.', $variantId),
                ], Response::HTTP_BAD_REQUEST);
            }

            $imageInputs = $payload['imageInputs'] ?? '[]';
            $imagePreview = $payload['imagePreview'] ?? '';

            $validated[] = [
                'module' => isset($socialById[$variantId]) ? 'social' : 'custom',
                'variant' => $socialById[$variantId] ?? $customById[$variantId],
                'canvas' => $payload['canvas'],
                'textInputs' => $payload['textInputs'],
                'imageInputs' => is_string($imageInputs) ? $imageInputs : '[]',
                'imagePreview' => is_string($imagePreview) ? $imagePreview : '',
            ];
        }

        foreach ($validated as $entry) {
            $message = $entry['module'] === 'social'
                ? new EditSocialNetworkTemplateVariantCanvasEditor(
                    $entry['variant']->id,
                    $entry['canvas'],
                    EditorTextInput::createCollectionFromJson($entry['textInputs']),
                    EditorImageInput::createCollectionFromJson($entry['imageInputs']),
                    previewImageDataUri: $entry['imagePreview'],
                )
                : new EditCustomTemplateVariantCanvasEditor(
                    $entry['variant']->id,
                    $entry['canvas'],
                    EditorTextInput::createCollectionFromJson($entry['textInputs']),
                    EditorImageInput::createCollectionFromJson($entry['imageInputs']),
                    previewImageDataUri: $entry['imagePreview'],
                );

            $this->bus->dispatch($message);
        }

        return $this->json([
            'status' => 'success',
            'message' => 'Autosave successful!',
            'lastSaved' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
