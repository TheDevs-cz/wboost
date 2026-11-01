<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
use WBoost\Web\Mcp\Fill\DocumentLoader;
use WBoost\Web\Mcp\Fill\DocumentPresenter;
use WBoost\Web\Mcp\Response\DocumentSummaryResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\TemplateDocument\AddTemplateDocumentPage;
use WBoost\Web\Message\TemplateDocument\CreateTemplateDocument;
use WBoost\Web\FormData\TemplateVariantFormData;
use WBoost\Web\Message\Template\CopyTemplate;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocument;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocumentPrintSettings;
use WBoost\Web\Message\TemplateDocument\InsertTemplateDocumentPage;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentFields;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\TemplateDocument\DocumentWriteInput;

/**
 * `manage_document` — arranging a multi-page document: one tool with an
 * `action`, because no other tool edits document STRUCTURE (`set_design`
 * writes one canvas) and five near-identical tools would compete for the same
 * request. Every action dispatches the web's own CQRS message, so the same
 * invariants answer. There is deliberately no delete (MCP rule: drafts, never
 * destruction) — detaching a page via `set_pages` keeps its template, which
 * the user may then delete on the web.
 */
#[McpToolScope(McpScope::TemplatesDesign)]
readonly final class ManageDocumentTool
{
    public function __construct(
        private MessageBusInterface $bus,
        private Security $security,
        private ProjectRepository $projectRepository,
        private TemplateDocumentRepository $documentRepository,
        private DocumentLoader $loader,
        private DocumentPresenter $presenter,
        private ProvideIdentity $provideIdentity,
        private TemplateCategoryRepository $categoryRepository,
    ) {
    }

    /**
     * Creates or arranges a multi-page document — ordinary templates (its
     * pages) filled together and exported as ONE file (export_document).
     * Answers the document as it is afterwards, with editorUrl / fillUrl.
     *
     * Actions:
     * - "create": projectId, name, dimension {unit: "mm"|"cm"|"px", unitWidth, unitHeight, bleedMm?, cropMarks?} or {preset: "1:1"|"4:5"|"9:16"}, templateIds? (existing templates to attach as the first pages — each needs a variant of exactly that size; synchronized/grouped templates and pages of another document are refused).
     * - "rename": documentId, name?, categoryId? (a category of the project — find_templates templates[].categoryId; "" removes it). An omitted one stays.
     * - "set_pages": documentId, templateIds — the WHOLE ordered page list: attach, detach (the template stays, it just leaves the document) and reorder in one call.
     * - "add_page": documentId, name?, position? (0-based; default last) — a BLANK page in the document size. Its variantId is in the answer (the page at that position): design it next with set_design.
     * - "set_fields": documentId, fields [{id?, name, kind: "text"|"image"|"shape", bindings: [{pageId, inputId}]}] — the WHOLE list of shared fields: one value the user types once, filled into the bound input on every page. Keep a field's id to keep it; at most one binding per page. text binds any editable text input (plain, rich, list, checklist — the field accepts the richest value EVERY binding accepts, its fields[].textMode; a checklist binds only to checklists), image binds image slots, shape binds recolourable shapes (one colour). Input ids come from describe_variant of the page's variantId.
     * - "duplicate_page": documentId, pageId, position? (0-based; default right after the original) — a copy of the page (its own template, same design, same input ids — values still stay per page).
     * - "set_print": documentId, bleedMm? (0–10), cropMarks? — print setup of the document AND every page (mm / cm documents only); an omitted one keeps its value.
     *
     * Pages never share values implicitly — not even duplicated pages with
     * identical input ids; only a shared field joins them.
     *
     * @param string $action create | rename | set_pages | add_page | set_fields | duplicate_page | set_print
     * @param null|string $projectId For "create": the project (get_context projects[].id).
     * @param null|string $documentId For every action except "create".
     * @param null|string $name For "create" / "rename"; optional page name for "add_page".
     * @param null|array<string, mixed> $dimension For "create": the page size every page renders in.
     * @param null|list<string> $templateIds For "create" / "set_pages": template ids in page order.
     * @param null|int $position For "add_page" / "duplicate_page": 0-based insert position.
     * @param null|list<mixed> $fields For "set_fields".
     * @param null|string $pageId For "duplicate_page": documents[].pages[].pageId.
     * @param null|string $categoryId For "rename": the category ("" = none).
     * @param null|float $bleedMm For "set_print".
     * @param null|bool $cropMarks For "set_print".
     */
    #[McpTool(name: 'manage_document')]
    public function __invoke(
        #[Schema(enum: ['create', 'rename', 'set_pages', 'add_page', 'set_fields', 'duplicate_page', 'set_print'])]
        string $action,
        null|string $projectId = null,
        null|string $documentId = null,
        null|string $name = null,
        #[Schema(type: 'object', additionalProperties: true)]
        null|array $dimension = null,
        #[Schema(type: 'array', items: ['type' => 'string'])]
        null|array $templateIds = null,
        null|int $position = null,
        #[Schema(type: 'array', items: ['type' => 'object'])]
        null|array $fields = null,
        null|string $pageId = null,
        null|float $bleedMm = null,
        null|bool $cropMarks = null,
        null|string $categoryId = null,
    ): DocumentSummaryResponse {
        try {
            $id = match ($action) {
                'create' => $this->create($projectId, $name, $dimension, $templateIds ?? []),
                'rename' => $this->rename(self::required($documentId, 'documentId'), $name, $categoryId),
                'set_pages' => $this->setPages(self::required($documentId, 'documentId'), $templateIds),
                'add_page' => $this->addPage(self::required($documentId, 'documentId'), $name, $position),
                'set_fields' => $this->setFields(self::required($documentId, 'documentId'), $fields),
                'duplicate_page' => $this->duplicatePage(self::required($documentId, 'documentId'), self::required($pageId, 'pageId'), $position),
                'set_print' => $this->setPrint(self::required($documentId, 'documentId'), $bleedMm, $cropMarks),
                default => throw new ToolCallException('action must be one of: create, rename, set_pages, add_page, set_fields, duplicate_page, set_print.'),
            };
        } catch (\InvalidArgumentException $invalid) {
            throw new ToolCallException($invalid->getMessage());
        }

        return $this->presenter->present($this->documentRepository->get($id));
    }

    /**
     * @param null|array<string, mixed> $dimension
     * @param array<mixed> $templateIds
     */
    private function create(null|string $projectId, null|string $name, null|array $dimension, array $templateIds): UuidInterface
    {
        $projectId = self::required($projectId, 'projectId');

        if (!Uuid::isValid($projectId)) {
            throw new ToolCallException(sprintf('No project with id %s exists, or this account cannot see it.', $projectId));
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw new ToolCallException(sprintf('No project with id %s exists, or this account cannot see it.', $projectId));
        }

        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw new ToolCallException(sprintf('No project with id %s exists, or this account cannot see it.', $projectId));
        }

        if (!$this->security->isGranted(ProjectVoter::EDIT, $project) || !$this->security->isGranted(User::ROLE_DESIGNER)) {
            throw new ToolCallException('Creating documents is a designer tool of the project owner.');
        }

        $documentId = $this->provideIdentity->next();
        $this->dispatch(new CreateTemplateDocument(
            $documentId,
            $project->id,
            self::name($name, 60),
            null,
            DocumentWriteInput::dimension($dimension ?? []),
            self::uuids($templateIds),
        ));

        return $documentId;
    }

    private function rename(string $documentId, null|string $name, null|string $categoryId): UuidInterface
    {
        $document = $this->loader->writable($documentId);

        if ($name === null && $categoryId === null) {
            throw new ToolCallException('"rename" needs name and/or categoryId.');
        }

        $category = $document->category?->id;

        if ($categoryId !== null) {
            $categoryId = trim($categoryId);
            $category = null;

            if ($categoryId !== '') {
                try {
                    $match = Uuid::isValid($categoryId) ? $this->categoryRepository->get(Uuid::fromString($categoryId)) : null;
                } catch (TemplateCategoryNotFound) {
                    $match = null;
                }

                if ($match === null || !$match->project->id->equals($document->project->id)) {
                    throw new ToolCallException(sprintf('No category %s in this project — use find_templates templates[].categoryId, or "" to remove the category.', $categoryId));
                }

                $category = $match->id;
            }
        }

        $this->dispatch(new EditTemplateDocument($document->id, $name !== null ? self::name($name, 60) : $document->name, $category));

        return $document->id;
    }

    /**
     * @param null|array<mixed> $templateIds
     */
    private function setPages(string $documentId, null|array $templateIds): UuidInterface
    {
        $document = $this->loader->writable($documentId);

        if ($templateIds === null) {
            throw new ToolCallException('"set_pages" needs templateIds — the whole ordered page list ([] detaches every page).');
        }

        $this->dispatch(new SetTemplateDocumentPages($document->id, self::uuids($templateIds)));

        return $document->id;
    }

    private function addPage(string $documentId, null|string $name, null|int $position): UuidInterface
    {
        $document = $this->loader->writable($documentId);
        $pageName = $name !== null && trim($name) !== '' ? self::name($name, 30) : sprintf('Strana %d', count($document->pages()) + 1);

        $this->dispatch(new AddTemplateDocumentPage(
            $document->id,
            $this->provideIdentity->next(),
            $this->provideIdentity->next(),
            $this->provideIdentity->next(),
            $pageName,
            $position,
        ));

        return $document->id;
    }

    /**
     * @param null|array<mixed> $fields
     */
    private function setFields(string $documentId, null|array $fields): UuidInterface
    {
        $document = $this->loader->writable($documentId);

        if ($fields === null) {
            throw new ToolCallException('"set_fields" needs fields — the whole list of shared fields ([] removes them all).');
        }

        $this->dispatch(new SetTemplateDocumentFields(
            $document->id,
            DocumentWriteInput::fields($document, $fields, $this->provideIdentity->next(...)),
        ));

        return $document->id;
    }

    private function duplicatePage(string $documentId, string $pageId, null|int $position): UuidInterface
    {
        $document = $this->loader->writable($documentId);
        $page = Uuid::isValid($pageId)
            ? ($document->page(Uuid::fromString($pageId)) ?? $document->pageOfTemplate(Uuid::fromString($pageId)))
            : null;

        if ($page === null) {
            throw new ToolCallException(sprintf('No page %s in this document — use documents[].pages[].pageId.', $pageId));
        }

        $newTemplateId = $this->provideIdentity->next();
        $newVariantId = $this->provideIdentity->next();

        // The web's own two steps: copy the page's template (only the
        // document-size variant), then insert the copy as a page.
        $this->dispatch(new CopyTemplate($page->template->id, $newTemplateId, [$page->variant->id->toString() => $newVariantId]));
        $this->dispatch(new InsertTemplateDocumentPage($document->id, $this->provideIdentity->next(), $newVariantId, $position ?? $page->position + 1));

        return $document->id;
    }

    private function setPrint(string $documentId, null|float $bleedMm, null|bool $cropMarks): UuidInterface
    {
        $document = $this->loader->writable($documentId);
        $dimension = $document->dimension;

        if (!$dimension->isPrintFormat()) {
            throw new ToolCallException('Bleed and crop marks exist only for print documents (mm / cm); this one is a screen (px) format.');
        }

        if ($bleedMm === null && $cropMarks === null) {
            throw new ToolCallException('"set_print" needs bleedMm and/or cropMarks.');
        }

        $this->dispatch(new EditTemplateDocumentPrintSettings(
            $document->id,
            max(0.0, min((float) TemplateVariantFormData::MAX_BLEED_MM, $bleedMm ?? $dimension->bleedMm)),
            $cropMarks ?? $dimension->cropMarks,
        ));

        return $document->id;
    }

    private function dispatch(object $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if ($real instanceof InvalidTemplateDocumentChange) {
                throw new ToolCallException(sprintf('%s (%s%s)', $real->getMessage(), $real->reason, $real->templateId !== null ? ', templateId ' . $real->templateId : ''));
            }

            throw $failed;
        }
    }

    private static function required(null|string $value, string $field): string
    {
        if ($value === null || trim($value) === '') {
            throw new ToolCallException(sprintf('"%s" is required for this action.', $field));
        }

        return trim($value);
    }

    private static function name(null|string $name, int $max): string
    {
        $name = trim($name ?? '');

        if ($name === '') {
            throw new ToolCallException('"name" is required and must not be empty.');
        }

        return mb_substr($name, 0, $max);
    }

    /**
     * @param array<mixed> $values
     * @return list<UuidInterface>
     */
    private static function uuids(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            if (!is_string($value) || !Uuid::isValid($value)) {
                throw new ToolCallException('templateIds must be template UUIDs (find_templates templates[].id).');
            }

            $result[] = Uuid::fromString($value);
        }

        return $result;
    }
}
