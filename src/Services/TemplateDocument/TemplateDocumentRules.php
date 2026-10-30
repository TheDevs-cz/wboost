<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\TemplateNotFound;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldKind;
use WBoost\Web\Value\EditorTextInput;

/**
 * The invariants of a multi-page document, in ONE place for every writer
 * (web, API, MCP all dispatch the same messages). Raw ids arrive from the
 * outside, so nothing here trusts them: same project, not grouped, not a page
 * elsewhere, a variant of the document's size, ≤ MAX_PAGES, and for shared
 * fields — a bindable input on a page of THIS document, one binding per page.
 */
readonly final class TemplateDocumentRules
{
    public function __construct(
        private TemplateRepository $templateRepository,
        private TemplateDocumentRepository $documentRepository,
    ) {
    }

    /**
     * Resolves and validates a template about to become a page of
     * `$document`; returns it with the variant the page will render.
     *
     * @return array{Template, TemplateVariant}
     *
     * @throws InvalidTemplateDocumentChange
     */
    public function attachable(TemplateDocument $document, UuidInterface $templateId): array
    {
        $template = $this->attachableTemplate($document, $templateId);
        $variant = $this->matchingVariant($document, $template);

        if ($variant === null) {
            throw InvalidTemplateDocumentChange::noMatchingVariant($templateId->toString(), $document->dimension->label());
        }

        return [$template, $variant];
    }

    /**
     * Same checks for an EXPLICIT variant (a page duplicate attaches the copy
     * of the page's own variant — the template was created in this request,
     * so its in-memory variants collection is still empty).
     *
     * @throws InvalidTemplateDocumentChange
     */
    public function assertAttachableVariant(TemplateDocument $document, TemplateVariant $variant): void
    {
        $this->attachableTemplate($document, $variant->template->id);

        if (!$variant->dimension->hasSameSizeAs($document->dimension)) {
            throw InvalidTemplateDocumentChange::noMatchingVariant($variant->template->id->toString(), $document->dimension->label());
        }
    }

    /**
     * @throws InvalidTemplateDocumentChange
     */
    private function attachableTemplate(TemplateDocument $document, UuidInterface $templateId): Template
    {
        try {
            $template = $this->templateRepository->get($templateId);
        } catch (TemplateNotFound) {
            throw InvalidTemplateDocumentChange::foreignTemplate($templateId->toString());
        }

        if (!$template->project->id->equals($document->project->id)) {
            throw InvalidTemplateDocumentChange::foreignTemplate($templateId->toString());
        }

        if ($template->group !== null) {
            throw InvalidTemplateDocumentChange::templateGrouped($templateId->toString());
        }

        $existing = $this->documentRepository->pageOfTemplate($template->id);

        if ($existing !== null && !$existing->document->id->equals($document->id)) {
            throw InvalidTemplateDocumentChange::alreadyAPage($templateId->toString());
        }

        return $template;
    }

    /**
     * The template's variant of the document's size — the oldest one when a
     * template holds several (a duplicated variant).
     */
    public function matchingVariant(TemplateDocument $document, Template $template): null|TemplateVariant
    {
        $variants = array_values(array_filter(
            $template->variants(),
            static fn (TemplateVariant $variant): bool => $variant->dimension->hasSameSizeAs($document->dimension),
        ));

        usort($variants, static fn (TemplateVariant $a, TemplateVariant $b): int => $a->createdAt <=> $b->createdAt ?: strcmp($a->id->toString(), $b->id->toString()));

        return $variants[0] ?? null;
    }

    /**
     * @param list<UuidInterface> $templateIds
     *
     * @throws InvalidTemplateDocumentChange
     */
    public function assertPageList(array $templateIds): void
    {
        if (count($templateIds) > TemplateDocument::MAX_PAGES) {
            throw InvalidTemplateDocumentChange::tooManyPages(TemplateDocument::MAX_PAGES);
        }

        $seen = [];

        foreach ($templateIds as $templateId) {
            $key = $templateId->toString();

            if (isset($seen[$key])) {
                throw InvalidTemplateDocumentChange::duplicatePage($key);
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param list<DocumentFieldBinding> $bindings
     *
     * @throws InvalidTemplateDocumentChange
     */
    public function assertBindings(TemplateDocument $document, string $fieldName, DocumentFieldKind $kind, array $bindings): void
    {
        $pages = [];

        foreach ($bindings as $binding) {
            if (isset($pages[$binding->templateId])) {
                throw InvalidTemplateDocumentChange::invalidBinding(sprintf('Pole „%s" smí být na každé stránce propojeno jen jednou.', $fieldName));
            }

            $pages[$binding->templateId] = true;

            $page = null;
            foreach ($document->pages() as $candidate) {
                if ($candidate->template->id->toString() === $binding->templateId) {
                    $page = $candidate;
                }
            }

            if ($page === null) {
                throw InvalidTemplateDocumentChange::invalidBinding(sprintf('Pole „%s" odkazuje na stránku, která v dokumentu není.', $fieldName));
            }

            if (!self::isBindable($page->variant, $binding->inputId, $kind)) {
                throw InvalidTemplateDocumentChange::invalidBinding(sprintf('Pole „%s" nelze propojit s vybraným prvkem stránky „%s".', $fieldName, $page->template->name));
            }
        }
    }

    /**
     * Text: a user-editable plain-text input (not locked, not a list / checklist —
     * a shared value is plain text in v1). Image: any image slot.
     */
    public static function isBindable(TemplateVariant $variant, string $inputId, DocumentFieldKind $kind): bool
    {
        if ($kind === DocumentFieldKind::Image) {
            foreach ($variant->imageInputs as $input) {
                if ($input->inputId === $inputId) {
                    return true;
                }
            }

            return false;
        }

        foreach ($variant->inputs as $input) {
            if ($input->inputId === $inputId) {
                return self::isBindableText($input);
            }
        }

        return false;
    }

    public static function isBindableText(EditorTextInput $input): bool
    {
        return !$input->locked && !$input->lists && !$input->checklist;
    }
}
