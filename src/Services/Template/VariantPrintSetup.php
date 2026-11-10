<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidPrintSetup;
use WBoost\Web\Message\Template\EditTemplateVariantPrintSettings;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Services\TemplateDocument\DocumentWriteInput;

/**
 * Crop marks of a STANDALONE print variant over the API
 * (`PATCH /api/template-variants/{id}/print-setup`) and MCP
 * (`set_print_setup`): the web "Tiskové nastavení" command
 * ({@see EditTemplateVariantPrintSettings}), validated by
 * {@see DocumentWriteInput::cropMarks()} so every machine writer shares one
 * rule and one set of codes. The bleed is fixed, so a `bleedMm` key from an
 * older client is ignored.
 *
 * - An absent `cropMarks` keeps the variant's current value (a no-op).
 * - Screen formats (px / preset) → `print_setup_not_available` (the web page
 *   does not exist for them either).
 * - A document page → `document_page` + `documentId`: one PDF never mixes
 *   print setups, so a page follows its document (`TemplateDocumentPageList::
 *   applyPrintSetup`) and is changed through it.
 * - A GROUPED variant is accepted and changes only itself, like the web
 *   form: a group has no print setup of its own, every member dimension
 *   carries its own crop marks.
 *
 * Authorisation (EDIT on the variant) is the caller's job.
 */
readonly final class VariantPrintSetup
{
    public function __construct(
        private MessageBusInterface $bus,
        private TemplateDocumentRepository $documentRepository,
    ) {
    }

    /**
     * @param array<array-key, mixed> $provided the raw request keys (`cropMarks`)
     *
     * @throws InvalidPrintSetup
     */
    public function change(TemplateVariant $variant, array $provided): void
    {
        $dimension = $variant->dimension;

        if (!$dimension->isPrintFormat()) {
            throw new InvalidPrintSetup(sprintf('Crop marks exist only for print formats (mm / cm); this variant is a screen format (%s) with no print PDF.', $dimension->label()), 'print_setup_not_available');
        }

        $page = $this->documentRepository->pageOfTemplate($variant->template->id);

        if ($page !== null && $page->variant->id->equals($variant->id)) {
            throw new InvalidPrintSetup(
                sprintf('This variant is a page of the document "%s" and follows its print setup — change the document\'s crop marks instead (it applies to every page).', $page->document->name),
                'document_page',
                $page->document->id->toString(),
            );
        }

        if (array_key_exists('cropMarks', $provided)) {
            $marks = DocumentWriteInput::cropMarks($dimension, $provided['cropMarks']);
            $this->bus->dispatch(new EditTemplateVariantPrintSettings($variant->id, $marks));
        }
    }
}
