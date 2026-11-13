<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Attribute\RequiresProjectModule;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScanAccess;
use WBoost\Web\Services\AiScan\AiScanLists;
use WBoost\Web\Services\AiScan\Template\ScanGalleryFolders;
use WBoost\Web\Services\Security\AiScanVoter;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\ProjectModule;

/**
 * "Šablona z obrázku" — the wizard's page (ux.md §5): one page, two routes.
 *
 * - `ai_scan_new`  `/project/{projectId}/sablony/z-obrazku` — upload (S1, S2);
 * - `ai_scan_page` `/project/{projectId}/sablony/z-obrazku/{scanId}` — one URL
 *   per scan: waiting (S3), stopped / failed (S5), review and pictures (S4,
 *   S4b), read-only after the template was created (T4). E-mails and MCP
 *   answers link here ({@see \WBoost\Web\Services\AiScan\AiScanUrls}).
 *
 * The page is a thin shell: it hands the browser the entry facts and the
 * scan's list row as JSON; `ai_scan_page_controller.js` draws every screen
 * and talks to the JSON endpoints of this folder. Nothing happens on a GET.
 *
 * Who: the new page needs somebody who may see the project's scans (an admin
 * or an editor) AND is entitled to start — everybody else goes back to the
 * template list, where the card says what to do. A scan's page needs USE on
 * the scan, so a proposal can be finished after AI scan was switched off.
 */
#[RequiresProjectModule(ProjectModule::Templates)]
final class AiScanPageController extends AbstractController
{
    public function __construct(
        readonly private AiScanAccess $access,
        readonly private AiScanLists $lists,
        readonly private AiScanRepository $repository,
        readonly private ScanGalleryFolders $galleryFolders,
    ) {
    }

    #[Route(path: '/project/{projectId}/sablony/z-obrazku', name: 'ai_scan_new', requirements: ['projectId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[Route(path: '/project/{projectId}/sablony/z-obrazku/{scanId}', name: 'ai_scan_page', requirements: ['projectId' => '[0-9a-fA-F-]{36}', 'scanId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        null|string $scanId = null,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);

        $scan = null;

        if ($scanId !== null) {
            $scan = $this->repository->find(Uuid::fromString($scanId));

            if (!$scan instanceof AiScan || !$scan->project->id->equals($project->id) || $scan->kind !== AiScanKind::Template) {
                throw $this->createNotFoundException();
            }

            $this->denyAccessUnlessGranted(AiScanVoter::USE, $scan);
        } elseif (!$this->access->canSeeProjectScans($user, $project)) {
            throw $this->createAccessDeniedException();
        }

        $entry = $this->lists->entry($project, $user);

        // Nobody without the entitlement starts here; the card in "Nová
        // šablona" is where access is asked for.
        if ($scan === null && in_array($entry['state'], ['locked', 'request_sent'], true)) {
            // Somebody followed a link (a bookmark, a colleague's message): say why
            // the page did not open and where access is asked for.
            $this->addFlash('info', $entry['state'] === 'request_sent'
                ? 'AI sken v tomto projektu zatím není zapnutý. Vaši žádost o přístup už máme — dáme vám vědět.'
                : 'AI sken v tomto projektu není zapnutý. O přístup požádáte tlačítkem „Požádat o přístup“ na kartě „Šablona z obrázku“ v nabídce „Nová šablona“.');

            return $this->redirectToRoute('templates', ['projectId' => $project->id->toString()]);
        }

        $response = $this->render('ai_scan/page.html.twig', [
            'project' => $project,
            'scan' => $scan === null ? null : $this->lists->row($scan, $user),
            'entry' => $entry,
            // Where the review's gallery picker opens: the scan's own folder
            // once pictures were uploaded for it, else the gallery root.
            'gallery_directory_id' => $scan === null ? null : $this->galleryFolders->find($scan)?->id->toString(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
