<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\FormData\TemplateDocumentFormData;
use WBoost\Web\FormType\TemplateDocumentFormType;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocument;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Services\Security\TemplateDocumentVoter;

final class EditTemplateDocumentController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private GetTemplateCategories $getTemplateCategories,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/edit', name: 'edit_template_document')]
    #[IsGranted(TemplateDocumentVoter::EDIT, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {

        $data = new TemplateDocumentFormData();
        $data->name = $document->name;
        $data->category = $document->category?->id->toString();

        $form = $this->createForm(TemplateDocumentFormType::class, $data, [
            'categories' => $this->getTemplateCategories->allForProject($document->project->id),
            'with_dimension' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(new EditTemplateDocument(
                $document->id,
                trim($data->name),
                $data->category !== null && Uuid::isValid($data->category) ? Uuid::fromString($data->category) : null,
            ));

            $this->addFlash('success', 'Dokument upraven.');

            return $this->redirectToRoute('template_document', ['documentId' => $document->id]);
        }

        return $this->render('edit_template_document.html.twig', [
            'form' => $form,
            'project' => $document->project,
            'document' => $document,
        ]);
    }
}
