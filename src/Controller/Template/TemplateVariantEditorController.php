<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\FormData\TemplateVariantEditorFormData;
use WBoost\Web\FormType\TemplateVariantEditorFormType;
use WBoost\Web\Message\Template\EditTemplateVariantCanvasEditor;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\Editor\ResolveEditorFontDefaults;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;

final class TemplateVariantEditorController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private GetManuals $getManuals,
        readonly private MessageBusInterface $bus,
        readonly private ClockInterface $clock,
        readonly private FileDirectoryRepository $fileDirectoryRepository,
        readonly private ResolveRichTextOptions $resolveRichTextOptions,
        readonly private ResolveEditorFontDefaults $resolveEditorFontDefaults,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/editor', name: 'template_variant_editor')]
    #[IsGranted(TemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        Request $request,
    ): Response {
        // Group-created variants are designed ONLY in the group editor — a
        // single-variant edit would be clobbered by the next group save.
        // (Manually added variants on a grouped template keep group = null and
        // are edited here; the group editor's click-to-switch only opens the
        // single editor for those, so this can't loop.)
        if ($variant->group !== null) {
            return $this->redirectToRoute('template_group_editor', [
                'groupId' => $variant->group->id,
            ]);
        }

        $template = $variant->template;
        $formData = new TemplateVariantEditorFormData();
        $editorForm = $this->createForm(TemplateVariantEditorFormType::class, $formData);

        $editorForm->handleRequest($request);

        if ($editorForm->isSubmitted() && $editorForm->isValid()) {
            assert(is_string($formData->canvas));
            assert(is_string($formData->textInputs));

            $this->bus->dispatch(
                new EditTemplateVariantCanvasEditor(
                    $variant->id,
                    $formData->canvas,
                    EditorTextInput::createCollectionFromJson($formData->textInputs),
                    EditorImageInput::createCollectionFromJson($formData->imageInputs ?? '[]'),
                    // Empty when the client couldn't render a preview (e.g. a
                    // tainted canvas); the handler keeps the existing thumbnail.
                    previewImageDataUri: $formData->imagePreview ?? '',
                ),
            );

            if ($request->headers->get('accept') === 'application/json') {
                return $this->json([
                    'status' => 'success',
                    'message' => 'Autosave successful!',
                    'lastSaved' => $this->clock->now()->format('Y-m-d H:i:s'),
                ]);
            }

            $this->addFlash('success', 'Editor uložen!');

            return $this->redirectToRoute('template_variants', [
                'templateId' => $template->id,
            ]);
        }

        $fonts = $this->getFonts->allForProject($template->project->id);
        $fontFaceNames = [];
        foreach ($fonts as $font) {
            foreach ($font->faces as $fontFace) {
                $fontFaceNames[] = "$font->name ($fontFace->name)";
            }
        }

        // Gallery folders offered as per-placeholder allow-lists in the image
        // properties panel (flat, alphabetical — the tree shape isn't needed here).
        $galleryDirectories = array_map(
            static fn (FileDirectory $directory): array => [
                'id' => $directory->id->toString(),
                'name' => $directory->name,
            ],
            $this->fileDirectoryRepository->listAll($template->project->id, FileSource::ProjectImage),
        );

        return $this->render('template_variant_editor.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
            'fonts' => $fonts,
            'editor_form' => $editorForm,
            'font_faces' => $fontFaceNames,
            'gallery_directories' => $galleryDirectories,
            'brand_colors' => ResolveRichTextOptions::computeColors($this->getManuals->allForProject($template->project->id)),
            // EVERY project face: the "Vzorový text" WYSIWYG narrows it to the
            // active input's offer client-side, and the "Uživatel může
            // přepínat písmo" checklist picks from the whole set.
            'rich_toolbar' => $this->resolveRichTextOptions->forProject($template->project->id)->toToolbarArray(),
            'font_defaults' => $this->resolveEditorFontDefaults->forProject($template->project->id),
            'menu_item' => 'templates',
            'module_label' => 'Šablony',
            'module_templates_url' => $this->generateUrl('templates', ['projectId' => $template->project->id]),
            'module_variants_url' => $this->generateUrl('template_variants', ['templateId' => $template->id]),
            'edit_variant_url' => $this->generateUrl('edit_template_variant', ['variantId' => $variant->id]),
            'export_url' => $this->generateUrl('template_variant_export', ['variantId' => $variant->id]),
            'dimension_label' => sprintf('%s (%dx%dpx)', $variant->dimension->label(), $variant->dimension->width(), $variant->dimension->height()),
        ]);
    }
}
