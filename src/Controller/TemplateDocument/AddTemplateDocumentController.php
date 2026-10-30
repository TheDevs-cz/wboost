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
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\FormData\TemplateDocumentFormData;
use WBoost\Web\FormType\TemplateDocumentFormType;
use WBoost\Web\Message\TemplateDocument\CreateTemplateDocument;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentsFeature;

/**
 * "Nový dokument": name, category and the page size. Pages are attached on
 * the document page right after (it opens the attach dialog).
 */
final class AddTemplateDocumentController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetTemplateCategories $getTemplateCategories,
        readonly private TemplateDocumentsFeature $feature,
    ) {
    }

    #[Route(path: '/project/{projectId}/add-template-document', name: 'add_template_document')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    #[IsGranted(User::ROLE_DESIGNER)]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        if (!$this->feature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $data = new TemplateDocumentFormData();
        $form = $this->createForm(TemplateDocumentFormType::class, $data, [
            'categories' => $this->getTemplateCategories->allForProject($project->id),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $documentId = $this->provideIdentity->next();

            $this->bus->dispatch(new CreateTemplateDocument(
                $documentId,
                $project->id,
                trim($data->name),
                $data->category !== null && Uuid::isValid($data->category) ? Uuid::fromString($data->category) : null,
                $data->dimension->dimension(),
            ));

            return $this->redirectToRoute('template_document', ['documentId' => $documentId, 'attach' => 1]);
        }

        return $this->render('add_template_document.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
