<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\FormData\TemplateVariantFormData;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\TemplateDocument\AddTemplateDocumentPage;
use WBoost\Web\Message\TemplateDocument\CreateTemplateDocument;
use WBoost\Web\Message\TemplateDocument\DeleteTemplateDocument;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocument;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocumentPrintSettings;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentFields;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\TemplateDocument\DocumentWriteInput;
use WBoost\Web\Value\TemplateDimension;

/**
 * Every document write over the API — a Request DTO turned into the SAME
 * CQRS message the web dispatches (CLAUDE.md rule: processors never mutate
 * entities). Invariant refusals answer 400 `{error, code, templateId?}`.
 *
 * @implements ProcessorInterface<CreateTemplateDocumentRequest|UpdateTemplateDocumentRequest|SetTemplateDocumentPagesRequest|AddTemplateDocumentPageRequest|SetTemplateDocumentFieldsRequest|null, TemplateDocumentResponse|Response>
 */
final readonly class TemplateDocumentWriteProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private Security $security,
        private ApiSubjectLoader $subjectLoader,
        private ProjectRepository $projectRepository,
        private TemplateDocumentRepository $documentRepository,
        private TemplateDocumentsProvider $provider,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TemplateDocumentResponse|Response
    {
        try {
            return match ($operation->getName()) {
                'api_template_document_create' => $this->create($data, $uriVariables['projectId'] ?? null),
                'api_template_document_update' => $this->update($data, $uriVariables['documentId'] ?? null, $context),
                'api_template_document_pages' => $this->setPages($data, $uriVariables['documentId'] ?? null),
                'api_template_document_add_page' => $this->addPage($data, $uriVariables['documentId'] ?? null),
                'api_template_document_fields' => $this->setFields($data, $uriVariables['documentId'] ?? null),
                'api_template_document_delete' => $this->delete($uriVariables['documentId'] ?? null),
                default => throw new \LogicException(sprintf('Unhandled operation "%s".', (string) $operation->getName())),
            };
        } catch (InvalidTemplateDocumentChange $refused) {
            return new JsonResponse([
                'error' => $refused->getMessage(),
                'code' => $refused->reason,
                ...($refused->templateId !== null ? ['templateId' => $refused->templateId] : []),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    private function create(mixed $data, mixed $projectId): TemplateDocumentResponse
    {
        if (!$data instanceof CreateTemplateDocumentRequest) {
            throw new BadRequestHttpException('Invalid body.');
        }

        if (!is_string($projectId) || !Uuid::isValid($projectId)) {
            throw new NotFoundHttpException();
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ProjectVoter::EDIT, $project) || !$this->security->isGranted(User::ROLE_DESIGNER)) {
            throw new AccessDeniedHttpException('Creating documents is a designer tool of the project owner.');
        }

        $documentId = $this->provideIdentity->next();
        $this->dispatch(new CreateTemplateDocument(
            $documentId,
            $project->id,
            self::name($data->name, 60),
            self::optionalUuid($data->categoryId, 'categoryId'),
            self::dimension($data->dimension),
            self::uuids($data->templateIds, 'templateIds'),
        ));

        return $this->respond($documentId);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function update(mixed $data, mixed $documentId, array $context): TemplateDocumentResponse
    {
        $document = $this->subjectLoader->writableDocument($documentId);

        if (!$data instanceof UpdateTemplateDocumentRequest) {
            throw new BadRequestHttpException('Invalid body.');
        }

        // An absent key means "leave it", a null categoryId "uncategorise" —
        // only the raw body tells the two apart.
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? json_decode($request->getContent(), true) : null;
        $provided = is_array($body) ? $body : [];

        $name = array_key_exists('name', $provided) ? self::name($data->name ?? '', 60) : $document->name;
        $categoryId = array_key_exists('categoryId', $provided)
            ? self::optionalUuid($data->categoryId, 'categoryId')
            : $document->category?->id;

        $this->dispatch(new EditTemplateDocument($document->id, $name, $categoryId));

        if (array_key_exists('bleedMm', $provided) || array_key_exists('cropMarks', $provided)) {
            if (!$document->dimension->isPrintFormat()) {
                throw new BadRequestHttpException('bleedMm / cropMarks exist only for print documents (mm / cm).');
            }

            $bleed = array_key_exists('bleedMm', $provided) ? ($data->bleedMm ?? 0.0) : $document->dimension->bleedMm;
            $this->dispatch(new EditTemplateDocumentPrintSettings(
                $document->id,
                max(0.0, min((float) TemplateVariantFormData::MAX_BLEED_MM, $bleed)),
                array_key_exists('cropMarks', $provided) ? ($data->cropMarks ?? false) : $document->dimension->cropMarks,
            ));
        }

        return $this->respond($document->id);
    }

    private function setPages(mixed $data, mixed $documentId): TemplateDocumentResponse
    {
        $document = $this->subjectLoader->writableDocument($documentId);

        if (!$data instanceof SetTemplateDocumentPagesRequest) {
            throw new BadRequestHttpException('Invalid body.');
        }

        $this->dispatch(new SetTemplateDocumentPages($document->id, self::uuids($data->templateIds, 'templateIds')));

        return $this->respond($document->id);
    }

    private function addPage(mixed $data, mixed $documentId): TemplateDocumentResponse
    {
        $document = $this->subjectLoader->writableDocument($documentId);

        if (!$data instanceof AddTemplateDocumentPageRequest) {
            throw new BadRequestHttpException('Invalid body.');
        }

        $name = trim($data->name) !== '' ? self::name($data->name, 30) : sprintf('Strana %d', count($document->pages()) + 1);

        $this->dispatch(new AddTemplateDocumentPage(
            $document->id,
            $this->provideIdentity->next(),
            $this->provideIdentity->next(),
            $this->provideIdentity->next(),
            $name,
            $data->position,
        ));

        return $this->respond($document->id);
    }

    private function setFields(mixed $data, mixed $documentId): TemplateDocumentResponse
    {
        $document = $this->subjectLoader->writableDocument($documentId);

        if (!$data instanceof SetTemplateDocumentFieldsRequest) {
            throw new BadRequestHttpException('Invalid body.');
        }

        try {
            $definitions = DocumentWriteInput::fields($document, $data->fields, $this->provideIdentity->next(...));
        } catch (\InvalidArgumentException $invalid) {
            throw new InvalidTemplateDocumentChange($invalid->getMessage(), 'invalid_binding');
        }

        $this->dispatch(new SetTemplateDocumentFields($document->id, $definitions));

        return $this->respond($document->id);
    }

    private function delete(mixed $documentId): Response
    {
        $document = $this->subjectLoader->writableDocument($documentId);
        $this->dispatch(new DeleteTemplateDocument($document->id, deletePages: false));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws InvalidTemplateDocumentChange
     */
    private function dispatch(object $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if ($real instanceof InvalidTemplateDocumentChange) {
                throw $real;
            }

            throw $failed;
        }
    }

    /**
     * The document as it is now — serialized by API Platform like every read
     * (POST answers 201, PUT / PATCH 200).
     */
    private function respond(UuidInterface $documentId): TemplateDocumentResponse
    {
        return $this->provider->present($this->documentRepository->get($documentId));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function dimension(array $data): TemplateDimension
    {
        try {
            return DocumentWriteInput::dimension($data);
        } catch (\InvalidArgumentException $invalid) {
            throw new BadRequestHttpException($invalid->getMessage());
        }
    }

    private static function name(string $name, int $max): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new BadRequestHttpException('"name" must not be empty.');
        }

        return mb_substr($name, 0, $max);
    }

    private static function optionalUuid(mixed $value, string $field): null|UuidInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new BadRequestHttpException(sprintf('"%s" must be a UUID or null.', $field));
        }

        return Uuid::fromString($value);
    }

    /**
     * @param array<mixed> $values
     * @return list<UuidInterface>
     */
    private static function uuids(array $values, string $field): array
    {
        $result = [];

        foreach ($values as $value) {
            if (!is_string($value) || !Uuid::isValid($value)) {
                throw new BadRequestHttpException(sprintf('"%s" must be a list of UUIDs.', $field));
            }

            $result[] = Uuid::fromString($value);
        }

        return $result;
    }

}
