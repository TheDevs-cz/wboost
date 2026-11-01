<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Fill;

use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Services\TemplateDocument\DocumentExportVersionApiView;

/**
 * The `{fields, pages}` document fill an MCP tool receives
 * (`export_document`, `document_history` "save_draft"): page keys
 * normalised to pageIds (a page's templateId is accepted too), optionally
 * laid OVER a stored version's fill — the agent's values win key by key.
 */
readonly final class DocumentFillInput
{
    public function __construct(
        private DocumentExportVersionApiView $versionView,
    ) {
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param array<array-key, mixed> $pages
     * @param list<string> $warnings receives one line per page key that matches no page
     * @return array{fields: array<array-key, mixed>, pages: array<string, array<array-key, mixed>>}
     */
    public function resolve(TemplateDocument $document, null|TemplateDocumentExportVersion $base, array $fields, array $pages, array &$warnings): array
    {
        $pageIdByKey = [];
        foreach ($document->pages() as $page) {
            $pageIdByKey[$page->id->toString()] = $page->id->toString();
            $pageIdByKey[$page->template->id->toString()] = $page->id->toString();
        }

        $normalized = [];
        foreach ($pages as $key => $entry) {
            $pageId = $pageIdByKey[(string) $key] ?? null;

            if ($pageId === null) {
                $warnings[] = sprintf('pages.%s matches no page of this document and was ignored (keys are documents[].pages[].pageId).', $key);
                continue;
            }

            $normalized[$pageId] = is_array($entry) ? $entry : [];
        }

        if ($base === null) {
            return ['fields' => $fields, 'pages' => $normalized];
        }

        $stored = self::plain($this->versionView->fill($base, $document));
        $storedFields = is_array($stored['fields'] ?? null) ? $stored['fields'] : [];
        $storedPages = is_array($stored['pages'] ?? null) ? $stored['pages'] : [];

        $merged = [];
        foreach ($storedPages as $pageId => $entry) {
            $merged[(string) $pageId] = is_array($entry) ? $entry : [];
        }

        foreach ($normalized as $pageId => $entry) {
            $into = $merged[$pageId] ?? [];

            foreach (['inputs', 'images', 'shapeColors'] as $section) {
                if (is_array($entry[$section] ?? null)) {
                    $into[$section] = array_replace(is_array($into[$section] ?? null) ? $into[$section] : [], $entry[$section]);
                }
            }

            $merged[$pageId] = $into;
        }

        return ['fields' => array_replace($storedFields, $fields), 'pages' => $merged];
    }

    /**
     * A version of THIS document, or a refusal.
     */
    public static function versionOf(TemplateDocument $document, null|TemplateDocumentExportVersion $version, string $versionId): TemplateDocumentExportVersion
    {
        if ($version === null || !$version->document->id->equals($document->id)) {
            throw new ToolCallException(sprintf('No version %s exists in this document\'s history. document_history "list" names them.', Uuid::isValid($versionId) ? $versionId : '"' . $versionId . '"'));
        }

        return $version;
    }

    /**
     * The view's ArrayObject empties back to plain arrays.
     *
     * @return array<array-key, mixed>
     */
    public static function plain(mixed $value): array
    {
        $decoded = json_decode((string) json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
