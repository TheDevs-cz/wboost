<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use WBoost\Web\Value\ManualContent\ManualContentStyles;
use WBoost\Web\Value\ManualContent\RichTextDoc;

/**
 * ProseMirror JSON → HTML for the manual's rich-text elements, by an
 * allow-list: only the nodes / marks below produce markup, every text and
 * attribute is escaped, links must be http(s)/mailto, and a font / colour
 * mark renders only when the manual still allows that value. The output is
 * therefore safe to print raw — there is no HTML sanitizer in this project
 * and none is needed, because no user HTML ever reaches this point.
 *
 * Defensive on its own: it re-checks every value instead of trusting that
 * the document went through {@see RichTextDoc::fromArray()}.
 */
readonly final class RichTextHtmlRenderer
{
    public function render(RichTextDoc $doc, ManualContentStyles $styles): string
    {
        $html = '';

        foreach ($doc->blocks() as $block) {
            $html .= $this->block($block, $styles);
        }

        return $html;
    }

    /**
     * @param array<mixed> $node
     */
    private function block(array $node, ManualContentStyles $styles): string
    {
        $type = $node['type'] ?? null;

        switch ($type) {
            case 'paragraph':
                $inline = $this->inline($this->content($node), $styles);

                return '<p>' . ($inline === '' ? '<br>' : $inline) . '</p>';

            case 'bullet_list':
                return '<ul>' . $this->items($node, $styles) . '</ul>';

            case 'ordered_list':
                $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
                $order = $attrs['order'] ?? 1;
                $start = is_int($order) && $order > 1 ? sprintf(' start="%d"', $order) : '';

                return '<ol' . $start . '>' . $this->items($node, $styles) . '</ol>';
        }

        return '';
    }

    /**
     * @param array<mixed> $list
     */
    private function items(array $list, ManualContentStyles $styles): string
    {
        $html = '';

        foreach ($this->content($list) as $item) {
            if (($item['type'] ?? null) !== 'list_item') {
                continue;
            }

            $inner = '';

            foreach ($this->content($item) as $block) {
                $inner .= $this->block($block, $styles);
            }

            $html .= '<li>' . $inner . '</li>';
        }

        return $html;
    }

    /**
     * @param list<array<mixed>> $nodes
     */
    private function inline(array $nodes, ManualContentStyles $styles): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;

            if ($type === 'hard_break') {
                $html .= '<br>';
                continue;
            }

            if ($type !== 'text' || !is_string($node['text'] ?? null)) {
                continue;
            }

            $html .= $this->markedText($node['text'], is_array($node['marks'] ?? null) ? $node['marks'] : [], $styles);
        }

        return $html;
    }

    /**
     * @param array<mixed> $marks
     */
    private function markedText(string $text, array $marks, ManualContentStyles $styles): string
    {
        $byType = [];

        foreach ($marks as $mark) {
            if (is_array($mark) && is_string($mark['type'] ?? null)) {
                $byType[$mark['type']] = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
            }
        }

        $html = self::escape($text);
        $css = [];

        if (isset($byType['font']) && is_string($byType['font']['family'] ?? null) && $styles->allowsFont($byType['font']['family'])) {
            $css[] = 'font-family: ' . self::cssString($byType['font']['family']);
        }

        if (isset($byType['size']) && RichTextDoc::isAllowedFontSize($byType['size']['size'] ?? null)) {
            $css[] = 'font-size: ' . $byType['size']['size'] . 'px';
        }

        if (isset($byType['color']) && is_string($byType['color']['color'] ?? null) && $styles->allowsColor($byType['color']['color'])) {
            $css[] = 'color: ' . RichTextDoc::normalizeColor($byType['color']['color']);
        }

        if ($css !== []) {
            $html = '<span style="' . self::escape(implode('; ', $css)) . '">' . $html . '</span>';
        }

        if (isset($byType['underline'])) {
            $html = '<u>' . $html . '</u>';
        }

        if (isset($byType['italic'])) {
            $html = '<em>' . $html . '</em>';
        }

        if (isset($byType['bold'])) {
            $html = '<strong>' . $html . '</strong>';
        }

        $href = $byType['link']['href'] ?? null;

        if (is_string($href) && RichTextDoc::isAllowedHref($href)) {
            $html = '<a href="' . self::escape($href) . '" target="_blank" rel="noopener noreferrer">' . $html . '</a>';
        }

        return $html;
    }

    /**
     * @param array<mixed> $node
     * @return list<array<mixed>>
     */
    private function content(array $node): array
    {
        $content = $node['content'] ?? [];

        if (!is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, is_array(...)));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * A CSS string literal in which every character outside a conservative
     * set is a CSS escape — a font name cannot close the string, the
     * declaration or the attribute it ends up in.
     */
    private static function cssString(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[^A-Za-z0-9 _().,-]/u',
            static fn (array $match): string => sprintf('\\%X ', mb_ord($match[0], 'UTF-8')),
            $value,
        );

        return '"' . ($escaped ?? '') . '"';
    }
}
