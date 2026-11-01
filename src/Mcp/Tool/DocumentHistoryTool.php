<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Fill\DocumentFillInput;
use WBoost\Web\Mcp\Fill\DocumentLoader;
use WBoost\Web\Mcp\Response\DocumentHistoryResponse;
use WBoost\Web\Mcp\Response\DocumentVersionResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\TemplateDocument\PinTemplateDocumentExportVersion;
use WBoost\Web\Message\TemplateDocument\RenameTemplateDocumentExportVersion;
use WBoost\Web\Message\TemplateDocument\SaveTemplateDocumentFillDraft;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Services\TemplateDocument\DocumentApiValues;
use WBoost\Web\Services\TemplateDocument\DocumentExportVersionApiView;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;

/**
 * `document_history` — a multi-page document's "Historie exportů": every
 * exported fill and every saved draft, the same shared list the web fill page
 * shows. One tool with an `action` (like `manage_document`): the actions share
 * one subject (the history) and one parameter shape, and splitting them would
 * add four near-identical tools. Scope `templates:export` — the history is
 * what exporting produces, and curating it is open to everyone who may fill
 * the document (the web rule). Nothing here deletes: an unpinned version only
 * ever leaves through the 100-version prune.
 */
#[McpToolScope(McpScope::TemplatesExport)]
readonly final class DocumentHistoryTool
{
    public function __construct(
        private DocumentLoader $loader,
        private GetExportVersions $getExportVersions,
        private TemplateDocumentExportVersionRepository $versionRepository,
        private DocumentExportVersionApiView $view,
        private DocumentFillInput $fillInput,
        private MessageBusInterface $bus,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * The export history of a multi-page document (find_templates
     * documents[]): every exported fill and saved draft, pinned first.
     *
     * Actions:
     * - "list": documentId → versions[] (id, name, pinned, draft, lastExportedAt, exportCount, channel, summary of the filled texts, loadUrl).
     * - "get": documentId, versionId → that version WITH `fill` = {fields, pages} in export_document's own shape. To re-export it, pass `versionId` to export_document (plus only what changes).
     * - "rename": documentId, versionId, name ("" or null clears the name).
     * - "pin" / "unpin": documentId, versionId — pinned versions stay on top and are never pruned.
     * - "save_draft": documentId, fields?, pages?, versionId? — stores the fill WITHOUT rendering as a "Rozpracováno" draft the user can open on the web (its loadUrl) and finish there. With the versionId of a draft (one this tool or the web saved), that draft is overwritten; otherwise a new draft starts, laid over the given version's fill when versionId names an export. Nothing is validated against the design until an export.
     *
     * @param string $action list | get | rename | pin | unpin | save_draft
     * @param string $documentId find_templates documents[].id
     * @param null|string $versionId versions[].id
     * @param null|string $name For "rename".
     * @param null|array<string, mixed> $fields For "save_draft": shared fieldId → value (as export_document).
     * @param null|array<string, mixed> $pages For "save_draft": pageId → {inputs, images, shapeColors} (as export_document).
     */
    #[McpTool(name: 'document_history')]
    public function __invoke(
        #[Schema(enum: ['list', 'get', 'rename', 'pin', 'unpin', 'save_draft'])]
        string $action,
        string $documentId,
        null|string $versionId = null,
        null|string $name = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $fields = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $pages = null,
    ): DocumentHistoryResponse {
        $document = $this->loader->viewable($documentId);

        return match ($action) {
            'list' => $this->respond($document, null),
            'get' => $this->respond($document, $this->version($document, $versionId), withFill: true),
            'rename' => $this->rename($document, $this->version($document, $versionId), $name),
            'pin', 'unpin' => $this->pin($document, $this->version($document, $versionId), $action === 'pin'),
            'save_draft' => $this->saveDraft($document, $versionId, $fields ?? [], $pages ?? []),
            default => throw new ToolCallException('action must be one of: list, get, rename, pin, unpin, save_draft.'),
        };
    }

    private function rename(TemplateDocument $document, TemplateDocumentExportVersion $version, null|string $name): DocumentHistoryResponse
    {
        $name = trim($name ?? '');
        $this->bus->dispatch(new RenameTemplateDocumentExportVersion($version->id, $name === '' ? null : $name));

        return $this->respond($document, $version);
    }

    private function pin(TemplateDocument $document, TemplateDocumentExportVersion $version, bool $pinned): DocumentHistoryResponse
    {
        $this->bus->dispatch(new PinTemplateDocumentExportVersion($version->id, $pinned));

        return $this->respond($document, $version);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param array<array-key, mixed> $pages
     */
    private function saveDraft(TemplateDocument $document, null|string $versionId, array $fields, array $pages): DocumentHistoryResponse
    {
        if ($document->pages() === []) {
            throw new ToolCallException('The document has no pages yet — there is nothing to fill.');
        }

        $given = $versionId !== null && trim($versionId) !== '' ? $this->version($document, $versionId) : null;
        $warnings = [];
        $resolved = $this->fillInput->resolve($document, $given, $fields, $pages, $warnings);

        $pageValues = [];
        foreach ($document->pages() as $page) {
            $pageValues[$page->template->id->toString()] = DocumentApiValues::forPage($document, $page, $resolved['fields'], $resolved['pages'][$page->id->toString()] ?? []);
        }

        $draftId = $given !== null && $given->draft ? $given->id : Uuid::uuid7();
        $user = $this->security->getUser();

        $this->bus->dispatch(new SaveTemplateDocumentFillDraft(
            $draftId,
            $document->id,
            $user instanceof User ? $user->id : null,
            DocumentFillValues::fromApiPages($pageValues),
            ExportChannel::Mcp,
        ));

        return $this->respond($document, $this->versionRepository->get($draftId), withFill: true, warnings: $warnings);
    }

    private function version(TemplateDocument $document, null|string $versionId): TemplateDocumentExportVersion
    {
        $versionId = trim($versionId ?? '');

        if ($versionId === '') {
            throw new ToolCallException('"versionId" is required for this action — document_history "list" names the versions.');
        }

        return DocumentFillInput::versionOf(
            $document,
            Uuid::isValid($versionId) ? $this->versionRepository->find(Uuid::fromString($versionId)) : null,
            $versionId,
        );
    }

    /**
     * @param list<string> $warnings
     */
    private function respond(TemplateDocument $document, null|TemplateDocumentExportVersion $version, bool $withFill = false, array $warnings = []): DocumentHistoryResponse
    {
        $routeParams = ['documentId' => $document->id->toString()];

        if ($version !== null) {
            return new DocumentHistoryResponse(
                documentId: $document->id->toString(),
                documentName: $document->name,
                versions: [],
                version: $this->present($document, $version, $this->view->summaries([$version], $document), $withFill),
                historyUrl: $this->urlGenerator->generate('template_document_export_history', $routeParams, UrlGeneratorInterface::ABSOLUTE_URL),
                warnings: $warnings,
            );
        }

        $versions = $this->getExportVersions->forDocument($document->id)->all();
        $summaries = $this->view->summaries($versions, $document);

        return new DocumentHistoryResponse(
            documentId: $document->id->toString(),
            documentName: $document->name,
            versions: array_map(fn (TemplateDocumentExportVersion $each): DocumentVersionResponse => $this->present($document, $each, $summaries, false), $versions),
            version: null,
            historyUrl: $this->urlGenerator->generate('template_document_export_history', $routeParams, UrlGeneratorInterface::ABSOLUTE_URL),
            warnings: $warnings,
        );
    }

    /**
     * @param array<string, array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int}> $summaries
     */
    private function present(TemplateDocument $document, TemplateDocumentExportVersion $version, array $summaries, bool $withFill): DocumentVersionResponse
    {
        return new DocumentVersionResponse(
            id: $version->id->toString(),
            name: $version->name,
            pinned: $version->isPinned(),
            draft: $version->draft,
            lastExportedAt: $version->lastExportedAt->format(\DATE_ATOM),
            exportCount: $version->exportCount,
            channel: $version->channel->value,
            summary: $summaries[$version->id->toString()] ?? ['texts' => [], 'pictures' => 0, 'hidden' => 0],
            loadUrl: $this->urlGenerator->generate('template_document_fill', ['documentId' => $document->id->toString(), 'version' => $version->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL),
            fill: $withFill ? $this->view->fill($version, $document) : null,
        );
    }
}
