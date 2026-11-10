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
 * Bleed + crop marks of a STANDALONE print variant over the API
 * (`PATCH /api/template-variants/{id}/print-setup`) and MCP
 * (`set_print_setup`): the web "Nastavení tisku" command
 * ({@see EditTemplateVariantPrintSettings} — its handler also re-fits the
 * background layer to the new bleed box), validated by
 * {@see DocumentWriteInput::printSettings()} so every machine writer shares
 * one rule and one set of codes.
 *
 * - An absent key keeps the variant's current value; both absent →
 *   `missing_print_setup`.
 * - Screen formats (px / preset) → `print_setup_not_available` (the web page
 *   does not exist for them either).
 * - A document page → `document_page` + `documentId`: one PDF never mixes
 *   print setups, so a page follows its document (`TemplateDocumentPageList::
 *   applyPrintSetup`) and is changed through it.
 * - A GROUPED variant is accepted and changes only itself, like the web
 *   form: a group has no print setup of its own, every member dimension
 *   carries its own bleed.
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
     * @param array<array-key, mixed> $provided the raw request keys (`bleedMm`, `cropMarks`)
     *
     * @throws InvalidPrintSetup
     */
    public function change(TemplateVariant $variant, array $provided): void
    {
        $hasBleed = array_key_exists('bleedMm', $provided);
        $hasMarks = array_key_exists('cropMarks', $provided);

        if (!$hasBleed && !$hasMarks) {
            throw new InvalidPrintSetup('Provide "bleedMm" and/or "cropMarks".', 'missing_print_setup');
        }

        $dimension = $variant->dimension;

        if (!$dimension->isPrintFormat()) {
            throw new InvalidPrintSetup(sprintf('Bleed and crop marks exist only for print formats (mm / cm); this variant is a screen format (%s) with no print PDF.', $dimension->label()), 'print_setup_not_available');
        }

        $page = $this->documentRepository->pageOfTemplate($variant->template->id);

        if ($page !== null && $page->variant->id->equals($variant->id)) {
            throw new InvalidPrintSetup(
                sprintf('This variant is a page of the document "%s" and follows its print setup — change the document\'s bleed / crop marks instead (it applies to every page).', $page->document->name),
                'document_page',
                $page->document->id->toString(),
            );
        }

        [$bleed, $marks] = DocumentWriteInput::printSettings(
            $dimension,
            $hasBleed ? $provided['bleedMm'] : $dimension->bleedMm,
            $hasMarks ? $provided['cropMarks'] : $dimension->cropMarks,
        );

        $this->bus->dispatch(new EditTemplateVariantPrintSettings($variant->id, $bleed, $marks));
    }
}
