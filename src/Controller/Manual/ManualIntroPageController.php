<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Manual\ManualContentPageEditor;
use WBoost\Web\Services\Security\ManualVoter;

/**
 * The intro content page editor ("Úvodní stránka" tab). A manual that has
 * no intro page yet opens with the defaults (IntroContentPageDefaults) —
 * nothing is written until the admin saves.
 */
final class ManualIntroPageController extends AbstractController
{
    public function __construct(
        readonly private ManualContentPageEditor $editor,
    ) {
    }

    #[Route(path: '/manual/{id}/uvodni-stranka', name: 'manual_intro_page', methods: ['GET'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(Manual $manual): Response
    {
        return $this->render('manual_content_page_editor.html.twig', $this->editor->introParameters($manual));
    }
}
