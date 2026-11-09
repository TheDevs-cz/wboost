<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Services\Manual\ManualContentPageEditor;
use WBoost\Web\Services\Security\ManualContentPageVoter;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;

/**
 * Editor + save of a CUSTOM content page, addressed by page id (the intro
 * keeps its manual-addressed routes — its id is redirected there).
 */
final class ManualContentPageController extends AbstractController
{
    public function __construct(
        readonly private ManualContentPageEditor $editor,
    ) {
    }

    #[Route(path: '/manual/stranka/{id}', name: 'manual_content_page', methods: ['GET'])]
    #[IsGranted(ManualContentPageVoter::EDIT, 'page')]
    public function edit(ManualContentPage $page): Response
    {
        if ($page->isIntro()) {
            return $this->redirectToRoute('manual_intro_page', ['id' => $page->manual->id]);
        }

        return $this->render('manual_content_page_editor.html.twig', $this->editor->customParameters($page));
    }

    #[Route(path: '/manual/stranka/{id}', name: 'manual_content_page_save', methods: ['POST'])]
    #[IsGranted(ManualContentPageVoter::EDIT, 'page')]
    public function save(ManualContentPage $page, Request $request): Response
    {
        return $this->editor->save($request, $page->manual, $page->role, $page->id);
    }
}
