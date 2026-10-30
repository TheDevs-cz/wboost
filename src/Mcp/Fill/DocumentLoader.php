<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Fill;

use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Services\Security\TemplateDocumentVoter;

/**
 * Loads a multi-page document for an MCP tool. A document the caller may not
 * see answers EXACTLY like an id that matches nothing (no enumeration oracle —
 * the find_templates rule); one it may see but not arrange says so.
 */
readonly final class DocumentLoader
{
    public function __construct(
        private Security $security,
        private TemplateDocumentRepository $documentRepository,
    ) {
    }

    public function viewable(string $documentId): TemplateDocument
    {
        return $this->load($documentId, TemplateDocumentVoter::VIEW);
    }

    public function writable(string $documentId): TemplateDocument
    {
        return $this->load($documentId, TemplateDocumentVoter::EDIT);
    }

    private function load(string $documentId, string $attribute): TemplateDocument
    {
        if (!Uuid::isValid($documentId)) {
            throw new ToolCallException(sprintf('"%s" is not a valid document id. Document ids are UUIDs; find_templates lists them under documents[].id.', $documentId));
        }

        try {
            $document = $this->documentRepository->get(Uuid::fromString($documentId));
        } catch (TemplateDocumentNotFound) {
            $document = null;
        }

        if ($document === null || !$this->security->isGranted(TemplateDocumentVoter::VIEW, $document)) {
            throw new ToolCallException(sprintf('No document with id %s exists, or this account cannot see it. find_templates lists the documents of a project.', $documentId));
        }

        if (!$this->security->isGranted($attribute, $document)) {
            throw new ToolCallException('Arranging a document (pages, shared fields, name) is a designer tool of the project owner — this account may fill and export it, but not change it.');
        }

        return $document;
    }
}
