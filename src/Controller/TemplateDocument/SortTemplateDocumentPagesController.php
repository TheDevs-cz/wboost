<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DispatchDocumentChange;

/**
 * The sidebar's drag & drop (the shared `dragula` Sortable controller posts
 * `{sorted: [templateId, …]}`). Only a REORDER is accepted here: the posted
 * set must be exactly the current pages, so a stale tab cannot detach pages
 * by accident.
 */
final class SortTemplateDocumentPagesController extends AbstractController
{
    public function __construct(
        readonly private DispatchDocumentChange $dispatch,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/sort-pages', name: 'sort_template_document_pages', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $sorted = is_array($data) && is_array($data['sorted'] ?? null) ? $data['sorted'] : [];

        $ids = [];
        foreach ($sorted as $id) {
            if (is_string($id) && Uuid::isValid($id)) {
                $ids[] = Uuid::fromString($id);
            }
        }

        $current = array_map(static fn ($page): string => $page->template->id->toString(), $document->pages());
        $posted = array_map(static fn (UuidInterface $id): string => $id->toString(), $ids);
        $sortedCurrent = $current;
        $sortedPosted = $posted;
        sort($sortedCurrent);
        sort($sortedPosted);

        if ($sortedCurrent !== $sortedPosted) {
            return new JsonResponse(['status' => 'error', 'error' => 'Stránky se mezitím změnily — obnovte stránku.'], 409);
        }

        $error = ($this->dispatch)(new SetTemplateDocumentPages($document->id, $ids));

        if ($error !== null) {
            return new JsonResponse(['status' => 'error', 'error' => $error->getMessage()], 400);
        }

        return new JsonResponse(['status' => 'success']);
    }
}
