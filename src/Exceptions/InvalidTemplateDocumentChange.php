<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A document change that breaks one of its invariants (a grouped template, a
 * template from another project, no variant of the document's size, too many
 * pages, a shared field binding two inputs of one page …). The message is
 * user-facing Czech; `code` is the machine-readable reason the API answers.
 */
#[WithHttpStatus(Response::HTTP_BAD_REQUEST)]
final class InvalidTemplateDocumentChange extends \Exception
{
    public function __construct(
        string $message,
        readonly public string $reason,
        readonly public null|string $templateId = null,
    ) {
        parent::__construct($message);
    }

    public static function templateGrouped(string $templateId): self
    {
        return new self('Synchronizovanou šablonu nelze přidat do dokumentu.', 'template_grouped', $templateId);
    }

    public static function foreignTemplate(string $templateId): self
    {
        return new self('Šablona nepatří do tohoto projektu.', 'template_not_found', $templateId);
    }

    public static function noMatchingVariant(string $templateId, string $size): self
    {
        return new self(sprintf('Šablona nemá variantu ve formátu dokumentu (%s).', $size), 'no_matching_variant', $templateId);
    }

    public static function alreadyAPage(string $templateId): self
    {
        return new self('Šablona už je stránkou jiného dokumentu.', 'template_in_other_document', $templateId);
    }

    public static function duplicatePage(string $templateId): self
    {
        return new self('Šablona může být v dokumentu jen jednou.', 'duplicate_page', $templateId);
    }

    public static function tooManyPages(int $max): self
    {
        return new self(sprintf('Dokument může mít nejvýše %d stran.', $max), 'too_many_pages');
    }

    public static function variantIsPage(string $documentName): self
    {
        return new self(sprintf('Varianta je stránkou dokumentu „%s" — nejdřív ji z dokumentu odpojte.', $documentName), 'variant_is_page');
    }

    public static function invalidBinding(string $message): self
    {
        return new self($message, 'invalid_binding');
    }
}
