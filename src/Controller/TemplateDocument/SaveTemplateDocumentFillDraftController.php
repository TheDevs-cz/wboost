<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboStreamResponse;
use WBoost\Web\Controller\Template\SaveTemplateVariantFillDraftController;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\TemplateDocument\SaveTemplateDocumentFillDraft;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Value\DocumentFillValues;

/**
 * "Uložit rozpracované" on the document fill page — the twin of the variant /
 * group draft endpoints (same CSRF id + field, same `fill_draft_controller`
 * contract): stores the form AS IT IS (no render, so it works while a text
 * overflows), overwriting the page's own draft in place. Answers a Turbo
 * Stream re-rendering the history dropdown + the draft id in
 * `X-Fill-Draft-Version`.
 */
final class SaveTemplateDocumentFillDraftController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private TemplateDocumentExportVersionRepository $versionRepository,
        readonly private Security $security,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/fill-draft', name: 'template_document_fill_draft', methods: ['POST'])]
    #[IsGranted(TemplateDocumentVoter::VIEW, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(SaveTemplateVariantFillDraftController::CSRF_TOKEN_ID, $request->request->getString('_fill_draft_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $draft = $this->versionRepository->findDraftOf($request->request->getString('draftVersionId'), $document->id);
        $versionId = $draft->id ?? Uuid::uuid7();
        $user = $this->security->getUser();

        $this->bus->dispatch(new SaveTemplateDocumentFillDraft(
            $versionId,
            $document->id,
            $user instanceof User ? $user->id : null,
            DocumentFillValues::fromWebForm($document, [
                'textValues' => $request->request->all('textValues'),
                'hiddenValues' => $request->request->all('hiddenValues'),
                'fontValues' => $request->request->all('fontValues'),
                'images' => $request->request->all('images'),
                'shapeColorValues' => $request->request->all('shapeColorValues'),
                'imagePlacements' => $request->request->all('imagePlacements'),
            ]),
        ));

        $response = new TurboStreamResponse($this->renderView('_document_history_menu.stream.html.twig', [
            'document' => $document,
            'versions' => $this->versionRepository->forDocument($document->id),
            'loaded_version' => $this->versionRepository->find($versionId),
        ]));
        $response->headers->set('X-Fill-Draft-Version', $versionId->toString());

        return $response;
    }
}
