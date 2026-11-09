<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Services\Manual\ManualContentEditorState;
use WBoost\Web\Services\Manual\ResolveManualContentStyles;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\ManualContent\ManualContentPagePayload;
use WBoost\Web\Value\MockupPageLayout;

/**
 * The intro content page editor ("Úvodní stránka" tab). A manual that has
 * no intro page yet opens with the defaults (IntroContentPageDefaults) —
 * nothing is written until the admin saves.
 */
final class ManualIntroPageController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'manual_content_page';

    public function __construct(
        readonly private ManualContentPageRepository $contentPageRepository,
        readonly private ResolveManualContentStyles $resolveStyles,
        readonly private ManualContentEditorState $editorState,
    ) {
    }

    #[Route(path: '/manual/{id}/uvodni-stranka', name: 'manual_intro_page', methods: ['GET'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Manual $manual): Response
    {
        $introPage = $this->contentPageRepository->introFor($manual->id);
        $styles = $this->resolveStyles->forManual($manual);

        return $this->render('manual_intro_page.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'intro_page' => $introPage,
            'content_styles' => $styles,
            'editor_state' => $this->editorState->forIntro($manual, $introPage),
            'layouts_geometry' => MockupPageLayout::exportGeometry(),
            'max_payload_bytes' => ManualContentPagePayload::MAX_BYTES,
        ]);
    }
}
