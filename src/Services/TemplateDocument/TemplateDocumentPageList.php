<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Services\ProvideIdentity;

/**
 * Replaces a document's page list by DIFFING it — never delete-all +
 * re-insert: Doctrine's UnitOfWork runs INSERTs before DELETEs within one
 * flush (and handlers never flush), so re-inserting a row for a template whose
 * old row is still pending deletion would violate UNIQUE(template_id). Kept
 * pages only move; dropped pages are removed (their shared-field bindings
 * with them); new pages are attached and take the document's print setup.
 */
readonly final class TemplateDocumentPageList
{
    public function __construct(
        private TemplateDocumentRules $rules,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @param list<UuidInterface> $templateIds
     *
     * @throws InvalidTemplateDocumentChange
     */
    public function set(TemplateDocument $document, array $templateIds): void
    {
        $this->rules->assertPageList($templateIds);

        // Validate everything before touching anything: a half-applied list
        // would still be flushed by the transaction middleware's commit.
        $attach = [];
        foreach ($templateIds as $templateId) {
            if ($document->pageOfTemplate($templateId) === null) {
                $attach[$templateId->toString()] = $this->rules->attachable($document, $templateId);
            }
        }

        $wanted = array_map(static fn (UuidInterface $id): string => $id->toString(), $templateIds);

        foreach ($document->pages() as $page) {
            if (!in_array($page->template->id->toString(), $wanted, true)) {
                $document->removePage($page);
            }
        }

        foreach ($templateIds as $position => $templateId) {
            $page = $document->pageOfTemplate($templateId);

            if ($page !== null) {
                $page->moveTo($position);
                continue;
            }

            [$template, $variant] = $attach[$templateId->toString()];
            self::applyPrintSetup($document, $variant);
            $document->addPage(new TemplateDocumentPage($this->provideIdentity->next(), $document, $template, $variant, $position));
        }
    }

    /**
     * One PDF never mixes print setups: a page takes the document's bleed +
     * crop marks when it joins (no-op for screen formats).
     */
    public static function applyPrintSetup(TemplateDocument $document, TemplateVariant $variant): void
    {
        if ($document->dimension->isPrintFormat()) {
            $variant->changePrintSettings($document->dimension->bleedMm, $document->dimension->cropMarks);
        }
    }
}
