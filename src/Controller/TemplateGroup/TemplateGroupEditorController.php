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
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Message\Template\EditTemplateVariantCanvasEditor;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
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
        readonly private GetManuals $getManuals,
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
        $memberVariants = $this->members->variants($group->id);

        if ($request->isMethod('POST')) {
            return $this->save($request, $memberVariants);
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

        foreach ($memberVariants as $variant) {
            $variants[] = [
                'variant' => $variant,
                'dimension_label' => sprintf('%s (%dx%dpx)', $variant->dimension->label(), $variant->dimension->width(), $variant->dimension->height()),
                'edit_variant_url' => $this->generateUrl('edit_template_variant', ['variantId' => $variant->id]),
                'export_url' => $this->generateUrl('template_variant_export', ['variantId' => $variant->id]),
            ];
        }

        return $this->render('template_group_editor.html.twig', [
            'project' => $group->project,
            'group' => $group,
            'fonts' => $fonts,
            'font_faces' => $fontFaceNames,
            'gallery_directories' => $galleryDirectories,
            'brand_colors' => ResolveRichTextOptions::computeColors($this->getManuals->allForProject($group->project->id)),
            'menu_item' => 'templates',
            'variants' => $variants,
            'save_url' => $this->generateUrl('template_group_editor', ['groupId' => $group->id]),
        ]);
    }

    /**
     * Save contract: `variants[<variantUuid>][canvas|textInputs|imageInputs|imagePreview]`,
     * one entry per included variant, same field semantics as the single-variant
     * editor form. Validates ALL entries before dispatching anything.
     *
     * @param list<TemplateVariant> $memberVariants
     */
    private function save(Request $request, array $memberVariants): Response
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

        $variantsById = [];
        foreach ($memberVariants as $variant) {
            $variantsById[$variant->id->toString()] = $variant;
        }

        $validated = [];

        foreach ($rawVariants as $variantId => $payload) {
            $variantId = (string) $variantId;

            // Only variants CREATED via the group are group-editable — a variant
            // added to a grouped template manually carries no group FK and is
            // rejected here.
            if (!isset($variantsById[$variantId])) {
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
                'variant' => $variantsById[$variantId],
                'canvas' => $payload['canvas'],
                'textInputs' => $payload['textInputs'],
                'imageInputs' => is_string($imageInputs) ? $imageInputs : '[]',
                'imagePreview' => is_string($imagePreview) ? $imagePreview : '',
            ];
        }

        foreach ($validated as $entry) {
            $this->bus->dispatch(new EditTemplateVariantCanvasEditor(
                $entry['variant']->id,
                $entry['canvas'],
                EditorTextInput::createCollectionFromJson($entry['textInputs']),
                EditorImageInput::createCollectionFromJson($entry['imageInputs']),
                previewImageDataUri: $entry['imagePreview'],
            ));
        }

        return $this->json([
            'status' => 'success',
            'message' => 'Autosave successful!',
            'lastSaved' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
