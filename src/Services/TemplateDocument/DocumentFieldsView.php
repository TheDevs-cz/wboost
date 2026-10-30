<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentField;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldDefinition;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * The designer's "Společná pole" card on the document page: the fields with
 * their bound pages (stale bindings flagged), the bindable inputs per page
 * for the add/edit modal, and one-click SUGGESTIONS — inputs sharing an
 * inputId (duplicated pages) or a name on ≥ 2 pages.
 *
 * Also owns {@see currentDefinitions()}, the full field list the fields
 * controller edits: a full replace re-validates EVERY field, so stale
 * bindings (an input deleted in the editor since) are dropped there instead
 * of making an unrelated edit fail.
 */
readonly final class DocumentFieldsView
{
    /**
     * @return array{
     *     fields: list<array{field: TemplateDocumentField, bindings: list<array{pageNumber: null|int, pageName: string, inputName: string, stale: bool}>, selected: array<string, string>, stale: int}>,
     *     pages: list<array{templateId: string, number: int, name: string, text: list<array{inputId: string, name: string, named: bool}>, image: list<array{inputId: string, name: string, named: bool}>}>,
     *     suggestions: list<array{name: string, kind: string, bindings: list<array{templateId: string, inputId: string}>, label: string}>
     * }
     */
    public function build(TemplateDocument $document): array
    {
        $pages = [];
        /** @var array<string, array{number: int, page: TemplateDocumentPage, names: array<string, string>}> $byTemplate */
        $byTemplate = [];

        foreach ($document->pages() as $index => $page) {
            $templateId = $page->template->id->toString();
            $options = self::bindableInputs($page);
            $names = [];
            foreach ([...$options['text'], ...$options['image']] as $option) {
                $names[$option['inputId']] = $option['name'];
            }

            $pages[] = [
                'templateId' => $templateId,
                'number' => $index + 1,
                'name' => $page->template->name,
                'text' => $options['text'],
                'image' => $options['image'],
            ];
            $byTemplate[$templateId] = ['number' => $index + 1, 'page' => $page, 'names' => $names];
        }

        $fields = [];
        /** @var array<string, true> $bound templateId|inputId → true */
        $bound = [];

        foreach ($document->fields() as $field) {
            $bindings = [];
            $selected = [];
            $stale = 0;

            foreach ($field->bindings() as $binding) {
                $source = $byTemplate[$binding->templateId] ?? null;
                $live = $source !== null && TemplateDocumentRules::isBindable($source['page']->variant, $binding->inputId, $field->kind);
                $bound[$binding->templateId . '|' . $binding->inputId] = true;

                if ($live) {
                    $selected[$binding->templateId] = $binding->inputId;
                } else {
                    $stale++;
                }

                $bindings[] = [
                    'pageNumber' => $source['number'] ?? null,
                    'pageName' => $source !== null ? $source['page']->template->name : '?',
                    'inputName' => $source['names'][$binding->inputId] ?? 'smazaný prvek',
                    'stale' => !$live,
                ];
            }

            usort($bindings, static fn (array $a, array $b): int => ($a['pageNumber'] ?? PHP_INT_MAX) <=> ($b['pageNumber'] ?? PHP_INT_MAX));

            $fields[] = ['field' => $field, 'bindings' => $bindings, 'selected' => $selected, 'stale' => $stale];
        }

        return [
            'fields' => $fields,
            'pages' => $pages,
            'suggestions' => self::suggestions($pages, $bound),
        ];
    }

    /**
     * The document's fields as definitions (order kept), with bindings that
     * no longer resolve dropped.
     *
     * @return list<DocumentFieldDefinition>
     */
    public function currentDefinitions(TemplateDocument $document): array
    {
        $definitions = [];

        foreach ($document->fields() as $field) {
            $definitions[] = new DocumentFieldDefinition(
                $field->id,
                $field->name,
                $field->kind,
                array_values(array_filter(
                    $field->bindings(),
                    static function (DocumentFieldBinding $binding) use ($document, $field): bool {
                        foreach ($document->pages() as $page) {
                            if ($page->template->id->toString() === $binding->templateId) {
                                return TemplateDocumentRules::isBindable($page->variant, $binding->inputId, $field->kind);
                            }
                        }

                        return false;
                    },
                )),
            );
        }

        return $definitions;
    }

    /**
     * @return array{text: list<array{inputId: string, name: string, named: bool}>, image: list<array{inputId: string, name: string, named: bool}>}
     */
    private static function bindableInputs(TemplateDocumentPage $page): array
    {
        $text = [];
        foreach (array_values($page->variant->inputs) as $index => $input) {
            if (TemplateDocumentRules::isBindableText($input)) {
                $text[] = ['inputId' => $input->inputId, 'name' => self::label($input->name, sprintf('Text %d', $index + 1)), 'named' => trim((string) $input->name) !== ''];
            }
        }

        $image = [];
        foreach (array_values($page->variant->imageInputs) as $index => $input) {
            $image[] = ['inputId' => $input->inputId, 'name' => self::label($input->name, sprintf('Obrázek %d', $index + 1)), 'named' => trim((string) $input->name) !== ''];
        }

        return ['text' => $text, 'image' => $image];
    }

    private static function label(null|string $name, string $fallback): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : $fallback;
    }

    /**
     * Unbound inputs that share an inputId (duplicated pages copy it
     * verbatim) or a name on at least two pages — the likely shared fields.
     * One input per page per suggestion; identical binding sets suggested once.
     *
     * @param list<array{templateId: string, number: int, name: string, text: list<array{inputId: string, name: string, named: bool}>, image: list<array{inputId: string, name: string, named: bool}>}> $pages
     * @param array<string, true> $bound
     *
     * @return list<array{name: string, kind: string, bindings: list<array{templateId: string, inputId: string}>, label: string}>
     */
    private static function suggestions(array $pages, array $bound): array
    {
        $result = [];
        $seen = [];

        foreach ([DocumentFieldKind::Text, DocumentFieldKind::Image] as $kind) {
            /** @var array<string, array<string, array{inputId: string, name: string, number: int}>> $groups group → templateId → input */
            $groups = [];

            foreach ($pages as $page) {
                foreach ($page[$kind->value] as $input) {
                    if (isset($bound[$page['templateId'] . '|' . $input['inputId']])) {
                        continue;
                    }

                    $candidate = ['inputId' => $input['inputId'], 'name' => $input['name'], 'number' => $page['number']];
                    $groups['id:' . $input['inputId']][$page['templateId']] ??= $candidate;
                    // Fallback labels ("Text 2") are positional, not a name.
                    if ($input['named']) {
                        $groups['name:' . mb_strtolower($input['name'])][$page['templateId']] ??= $candidate;
                    }
                }
            }

            foreach ($groups as $members) {
                if (count($members) < 2) {
                    continue;
                }

                $bindings = [];
                $numbers = [];
                foreach ($members as $templateId => $member) {
                    $bindings[] = ['templateId' => $templateId, 'inputId' => $member['inputId']];
                    $numbers[] = $member['number'];
                }

                $signature = $kind->value . ':' . implode(',', array_map(static fn (array $b): string => $b['templateId'] . '|' . $b['inputId'], $bindings));
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;

                $first = array_values($members)[0];
                $result[] = [
                    'name' => $first['name'],
                    'kind' => $kind->value,
                    'bindings' => $bindings,
                    'label' => 'strany ' . implode(', ', $numbers),
                ];
            }
        }

        return $result;
    }
}
