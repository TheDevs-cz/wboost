<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Message\Manual\SaveManualContentPage;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Value\ManualContent\ManualContentPagePayload;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\MockupPageLayout;

/**
 * The content page editor for both roles — the intro (`manual_intro_page`,
 * addressed by its manual: it may not exist yet) and custom pages
 * (`manual_content_page`, addressed by page id). Same template, same
 * Stimulus controller, same one-POST save answering `{state, preview}`.
 */
readonly final class ManualContentPageEditor
{
    public const string CSRF_TOKEN_ID = 'manual_content_page';

    public function __construct(
        private MessageBusInterface $bus,
        private ManualContentPageRepository $contentPageRepository,
        private ResolveManualContentStyles $resolveStyles,
        private ManualContentEditorState $editorState,
        private Environment $twig,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private ManualGalleryPicker $galleryPicker,
    ) {
    }

    /**
     * @return array<string, mixed> parameters of manual_content_page_editor.html.twig
     */
    public function introParameters(Manual $manual): array
    {
        $introPage = $this->contentPageRepository->introFor($manual->id);

        return $this->parameters(
            $manual,
            $introPage,
            true,
            $this->editorState->forIntro($manual, $introPage),
            $this->urlGenerator->generate('manual_intro_page_save', ['manualId' => $manual->id]),
        );
    }

    /**
     * @return array<string, mixed> parameters of manual_content_page_editor.html.twig
     */
    public function customParameters(ManualContentPage $page): array
    {
        return $this->parameters(
            $page->manual,
            $page,
            false,
            $this->editorState->forPage($page),
            $this->urlGenerator->generate('manual_content_page_save', ['id' => $page->id]),
        );
    }

    public function save(Request $request, Manual $manual, ManualContentPageRole $role, UuidInterface $pageId): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_token')))) {
            return new JsonResponse(['error' => 'Platnost formuláře vypršela. Obnovte stránku a uložte znovu.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = ManualContentPagePayload::fromJson($request->request->getString('payload'));

            $this->bus->dispatch(new SaveManualContentPage(
                $manual->id,
                $pageId,
                $role,
                $payload->name,
                $payload->layout,
                $payload->segments,
            ));
        } catch (InvalidManualContent $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if (!$real instanceof InvalidManualContent) {
                throw $failed;
            }

            return new JsonResponse(['error' => $real->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $page = $role === ManualContentPageRole::Intro
            ? $this->contentPageRepository->introFor($manual->id)
            : $this->contentPageRepository->find($pageId);

        return new JsonResponse([
            'state' => $page !== null ? $this->editorState->forPage($page) : $this->editorState->forIntro($manual, null),
            'preview' => $this->twig->render('_manual_content_page_preview.html.twig', [
                'manual' => $manual,
                'content_page' => $page,
                'is_intro' => $role === ManualContentPageRole::Intro,
                'content_styles' => $this->resolveStyles->forManual($manual),
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function parameters(Manual $manual, null|ManualContentPage $page, bool $isIntro, array $state, string $saveUrl): array
    {
        return [
            'project' => $manual->project,
            'manual' => $manual,
            'content_page' => $page,
            'is_intro' => $isIntro,
            'content_styles' => $this->resolveStyles->forManual($manual),
            'editor_state' => $state,
            'save_url' => $saveUrl,
            'layouts_geometry' => MockupPageLayout::exportGeometry(),
            'max_payload_bytes' => ManualContentPagePayload::MAX_BYTES,
            'gallery_directory_id' => $this->galleryPicker->directoryId($manual),
        ];
    }
}
