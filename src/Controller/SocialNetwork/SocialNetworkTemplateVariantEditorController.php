<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\FormData\SocialNetworkTemplateVariantEditorFormData;
use WBoost\Web\FormType\SocialNetworkTemplateVariantEditorFormType;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplateVariantCanvasEditor;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FileSource;

final class SocialNetworkTemplateVariantEditorController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
        readonly private MessageBusInterface $bus,
        readonly private ClockInterface $clock,
        readonly private FileDirectoryRepository $fileDirectoryRepository,
    ) {
    }

    #[Route(path: '/social-network-template-variant/{variantId}/editor', name: 'social_network_template_variant_editor')]
    #[IsGranted(SocialNetworkTemplateVariantVoter::EDIT, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
        Request $request,
    ): Response {
        $template = $variant->template;
        $formData = new SocialNetworkTemplateVariantEditorFormData();
        $editorForm = $this->createForm(SocialNetworkTemplateVariantEditorFormType::class, $formData);

        $editorForm->handleRequest($request);

        if ($editorForm->isSubmitted() && $editorForm->isValid()) {
            assert(is_string($formData->canvas));
            assert(is_string($formData->textInputs));
            assert(is_string($formData->imagePreview));

            $this->bus->dispatch(
                new EditSocialNetworkTemplateVariantCanvasEditor(
                    $variant->id,
                    $formData->canvas,
                    EditorTextInput::createCollectionFromJson($formData->textInputs),
                    EditorImageInput::createCollectionFromJson($formData->imageInputs ?? '[]'),
                    previewImageDataUri: $formData->imagePreview,
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

            return $this->redirectToRoute('social_network_template_variants', [
                'templateId' => $template->id,
            ]);
        }

        // Stage 7: the standalone background/upload forms are gone — both
        // operations now flow through the project image gallery
        // (Project:ImageGallery Live Component embedded in the editor
        // template). The `project_upload_file` route is hit directly by the
        // gallery's upload tab; the `edit_social_network_template_variant`
        // route is hit by the orchestrator with a `backgroundImagePath`
        // field when the user picks an existing asset as the background.
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
            $this->fileDirectoryRepository->listAll($template->project->id, FileSource::SocialNetworkImage),
        );

        return $this->render('social_network_template_variant_editor.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
            'fonts' => $fonts,
            'editor_form' => $editorForm,
            'font_faces' => $fontFaceNames,
            'gallery_directories' => $galleryDirectories,
        ]);
    }
}
