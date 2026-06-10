<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\FormData\CustomTemplateVariantEditorFormData;
use WBoost\Web\FormType\CustomTemplateVariantEditorFormType;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplateVariantCanvasEditor;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Services\Security\CustomTemplateVariantVoter;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;

final class CustomTemplateVariantEditorController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private MessageBusInterface $bus,
        readonly private ClockInterface $clock,
        readonly private FileDirectoryRepository $fileDirectoryRepository,
    ) {
    }

    #[Route(path: '/custom-template-variant/{variantId}/editor', name: 'custom_template_variant_editor')]
    #[IsGranted(CustomTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        CustomTemplateVariant $variant,
        Request $request,
    ): Response {
        $template = $variant->template;
        $formData = new CustomTemplateVariantEditorFormData();
        $editorForm = $this->createForm(CustomTemplateVariantEditorFormType::class, $formData);

        $editorForm->handleRequest($request);

        if ($editorForm->isSubmitted() && $editorForm->isValid()) {
            assert(is_string($formData->canvas));
            assert(is_string($formData->textInputs));

            $this->bus->dispatch(
                new EditCustomTemplateVariantCanvasEditor(
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

            return $this->redirectToRoute('custom_template_variants', [
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
            'menu_item' => 'custom_templates',
            'module_label' => 'Šablony',
            'module_templates_url' => $this->generateUrl('custom_templates', ['projectId' => $template->project->id]),
            'module_variants_url' => $this->generateUrl('custom_template_variants', ['templateId' => $template->id]),
            'edit_variant_url' => $this->generateUrl('edit_custom_template_variant', ['variantId' => $variant->id]),
            'export_url' => $this->generateUrl('custom_template_variant_export', ['variantId' => $variant->id]),
            'dimension_label' => sprintf('%s (%dx%dpx)', $variant->dimension->label(), $variant->dimension->width(), $variant->dimension->height()),
        ]);
    }
}
