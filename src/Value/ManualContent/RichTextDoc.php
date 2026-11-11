<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use WBoost\Web\Exceptions\InvalidManualContent;

/**
 * A rich-text element's content: a ProseMirror document as JSON — NEVER HTML.
 * The schema is the one `manual_content_editor_controller.js` defines, and it
 * is validated node by node against an allow-list on the way in:
 *
 * - blocks: `paragraph`, `bullet_list`, `ordered_list` (attrs.order ≥ 1),
 *   `list_item` (a paragraph first, then paragraphs / nested lists);
 * - inline: `text` (non-empty), `hard_break`;
 * - marks: `bold`, `italic`, `underline`, `link` (attrs.href http/https/mailto),
 *   `font` (attrs.family), `size` (attrs.size — integer px,
 *   {@see MIN_FONT_SIZE}–{@see MAX_FONT_SIZE}), `color` (attrs.color `#rrggbb`).
 *
 * Anything else — unknown node or mark, wrong nesting, a `javascript:` link —
 * throws. Unknown extra KEYS are dropped, so the stored document is always the
 * canonical shape. Whether a font / colour is one the manual may use is NOT
 * decided here (that list changes over time): {@see restrictStyles()} applies
 * it at save, and the HTML renderer applies it again at render.
 */
readonly final class RichTextDoc
{
    public const int MAX_DEPTH = 8;
    public const int MAX_NODES = 5000;
    public const int MAX_TEXT_LENGTH = 50000;
    public const int MAX_HREF_LENGTH = 2000;
    public const int MAX_FONT_LENGTH = 200;
    public const int MIN_FONT_SIZE = 8;
    public const int MAX_FONT_SIZE = 96;

    private const array SIMPLE_MARKS = ['bold', 'italic', 'underline'];

    /**
     * Canonical mark order — also the nesting order of the rendered HTML.
     */
    public const array MARK_ORDER = ['link', 'bold', 'italic', 'underline', 'font', 'size', 'color'];

    /**
     * @param array{type: 'doc', content: list<array<string, mixed>>} $doc
     */
    private function __construct(
        private array $doc,
    ) {
    }

    public static function empty(): self
    {
        return new self(['type' => 'doc', 'content' => [['type' => 'paragraph']]]);
    }

    /**
     * Plain text (an admin's legacy page-text override) as paragraphs:
     * blank-line-separated blocks become paragraphs, single newlines hard
     * breaks — the same reading `_manual_page_description.html.twig` gave it.
     */
    public static function fromPlainText(string $text): self
    {
        $text = str_replace("\r\n", "\n", $text);
        $paragraphs = [];

        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $content = [];

            foreach (explode("\n", $block) as $index => $line) {
                if ($index > 0) {
                    $content[] = ['type' => 'hard_break'];
                }

                $line = self::cleanText($line);

                if ($line !== '') {
                    $content[] = ['type' => 'text', 'text' => $line];
                }
            }

            $paragraphs[] = $content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content];
        }

        return $paragraphs === [] ? self::empty() : new self(['type' => 'doc', 'content' => $paragraphs]);
    }

    /**
     * @throws InvalidManualContent
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || ($data['type'] ?? null) !== 'doc') {
            throw new InvalidManualContent('Rich text must be a ProseMirror doc.');
        }

        $budget = new \ArrayObject(['nodes' => 0, 'text' => 0]);
        $blocks = self::children($data, 'doc', 0, $budget);

        if ($blocks === []) {
            return self::empty();
        }

        foreach ($blocks as $block) {
            self::assertBlock($block);
        }

        return new self(['type' => 'doc', 'content' => $blocks]);
    }

    /**
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return $this->doc;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return $this->doc['content'];
    }

    public function isEmpty(): bool
    {
        return trim($this->plainText()) === '';
    }

    public function plainText(): string
    {
        $text = '';
        $walk = static function (array $node) use (&$walk, &$text): void {
            if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
                $text .= $node['text'];
            }

            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($this->doc);

        return $text;
    }

    /**
     * Drops `font` / `color` marks whose value the manual does not allow
     * (a font removed from the manual, a colour no longer in the palette) —
     * the text keeps its other marks and simply falls back to the default.
     */
    public function restrictStyles(ManualContentStyles $styles): self
    {
        $filter = static function (array $node) use (&$filter, $styles): array {
            if (isset($node['marks']) && is_array($node['marks'])) {
                $marks = array_values(array_filter(
                    $node['marks'],
                    static fn (mixed $mark): bool => is_array($mark) && $styles->allowsMark($mark),
                ));

                if ($marks === []) {
                    unset($node['marks']);
                } else {
                    $node['marks'] = $marks;
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = array_map(
                    static fn (mixed $child): mixed => is_array($child) ? $filter($child) : $child,
                    $node['content'],
                );
            }

            return $node;
        };

        /** @var array{type: 'doc', content: list<array<string, mixed>>} $doc */
        $doc = $filter($this->doc);

        return new self($doc);
    }

    public static function isAllowedHref(string $href): bool
    {
        if ($href === '' || strlen($href) > self::MAX_HREF_LENGTH) {
            return false;
        }

        // No whitespace / control characters anywhere: browsers strip them
        // before parsing the scheme ("java\tscript:"), so they could smuggle
        // a scheme past a prefix check.
        if (preg_match('/[\x00-\x20\x7F]/', $href) === 1) {
            return false;
        }

        if (preg_match('~^(https?)://[^/?#\\\\]+~i', $href) === 1) {
            return true;
        }

        return preg_match('~^mailto:[^@\s]+@[^@\s]+$~i', $href) === 1;
    }

    /**
     * @phpstan-assert-if-true int $size
     */
    public static function isAllowedFontSize(mixed $size): bool
    {
        return is_int($size) && $size >= self::MIN_FONT_SIZE && $size <= self::MAX_FONT_SIZE;
    }

    /**
     * `#rgb` / `#rrggbb` (any case) → `#rrggbb` lowercase, or null.
     */
    public static function normalizeColor(mixed $color): null|string
    {
        if (!is_string($color) || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $match) !== 1) {
            return null;
        }

        $hex = strtolower($match[1]);

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . $hex;
    }

    /**
     * @param array<mixed> $node
     * @param \ArrayObject<string, int> $budget
     * @return list<array<string, mixed>>
     * @throws InvalidManualContent
     */
    private static function children(array $node, string $parentType, int $depth, \ArrayObject $budget): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidManualContent('Rich text is nested too deeply.');
        }

        $content = $node['content'] ?? [];

        if (!is_array($content) || !array_is_list($content)) {
            throw new InvalidManualContent('Node content must be a list.');
        }

        $children = [];

        foreach ($content as $child) {
            $budget['nodes'] = $budget['nodes'] + 1;

            if ($budget['nodes'] > self::MAX_NODES) {
                throw new InvalidManualContent('Rich text is too long.');
            }

            $normalized = self::node($child, $depth + 1, $budget);

            if ($normalized !== null) {
                $children[] = $normalized;
            }
        }

        return $children;
    }

    /**
     * @param \ArrayObject<string, int> $budget
     * @return null|array<string, mixed> null = drop (an empty text node)
     * @throws InvalidManualContent
     */
    private static function node(mixed $node, int $depth, \ArrayObject $budget): null|array
    {
        if (!is_array($node) || !is_string($node['type'] ?? null)) {
            throw new InvalidManualContent('Node must be an object with a type.');
        }

        $type = $node['type'];

        switch ($type) {
            case 'text':
                $text = $node['text'] ?? null;

                if (!is_string($text)) {
                    throw new InvalidManualContent('Text node without text.');
                }

                $text = self::cleanText($text);

                if ($text === '') {
                    return null;
                }

                $budget['text'] = $budget['text'] + mb_strlen($text);

                if ($budget['text'] > self::MAX_TEXT_LENGTH) {
                    throw new InvalidManualContent('Rich text is too long.');
                }

                $marks = self::marks($node['marks'] ?? []);

                return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];

            case 'hard_break':
                return ['type' => 'hard_break'];

            case 'paragraph':
                $inline = self::children($node, $type, $depth, $budget);

                foreach ($inline as $child) {
                    if (!in_array($child['type'], ['text', 'hard_break'], true)) {
                        throw new InvalidManualContent('A paragraph holds only text and line breaks.');
                    }
                }

                return $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];

            case 'bullet_list':
            case 'ordered_list':
                $items = self::children($node, $type, $depth, $budget);

                if ($items === []) {
                    throw new InvalidManualContent('A list needs at least one item.');
                }

                foreach ($items as $item) {
                    if ($item['type'] !== 'list_item') {
                        throw new InvalidManualContent('A list holds only list items.');
                    }
                }

                if ($type === 'bullet_list') {
                    return ['type' => $type, 'content' => $items];
                }

                $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
                $order = $attrs['order'] ?? 1;

                if (!is_int($order) || $order < 1 || $order > 100000) {
                    throw new InvalidManualContent('Ordered list start must be a positive integer.');
                }

                return ['type' => $type, 'attrs' => ['order' => $order], 'content' => $items];

            case 'list_item':
                $blocks = self::children($node, $type, $depth, $budget);

                if ($blocks === [] || $blocks[0]['type'] !== 'paragraph') {
                    throw new InvalidManualContent('A list item starts with a paragraph.');
                }

                foreach ($blocks as $block) {
                    self::assertBlock($block);
                }

                return ['type' => $type, 'content' => $blocks];
        }

        throw new InvalidManualContent(sprintf('Node type "%s" is not allowed.', mb_substr($type, 0, 40)));
    }

    /**
     * @param array<string, mixed> $block
     * @throws InvalidManualContent
     */
    private static function assertBlock(array $block): void
    {
        if (!in_array($block['type'], ['paragraph', 'bullet_list', 'ordered_list'], true)) {
            throw new InvalidManualContent('Only paragraphs and lists are allowed as blocks.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     * @throws InvalidManualContent
     */
    private static function marks(mixed $marks): array
    {
        if (!is_array($marks) || !array_is_list($marks)) {
            throw new InvalidManualContent('Marks must be a list.');
        }

        $byType = [];

        foreach ($marks as $mark) {
            if (!is_array($mark) || !is_string($mark['type'] ?? null)) {
                throw new InvalidManualContent('Mark must be an object with a type.');
            }

            $type = $mark['type'];
            $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];

            if (in_array($type, self::SIMPLE_MARKS, true)) {
                $byType[$type] = ['type' => $type];
                continue;
            }

            switch ($type) {
                case 'link':
                    $href = $attrs['href'] ?? null;

                    if (!is_string($href) || !self::isAllowedHref($href)) {
                        throw new InvalidManualContent('Links may only point to http(s) or mailto addresses.');
                    }

                    $byType[$type] = ['type' => $type, 'attrs' => ['href' => $href]];
                    continue 2;

                case 'font':
                    $family = $attrs['family'] ?? null;

                    if (!is_string($family) || $family === '' || mb_strlen($family) > self::MAX_FONT_LENGTH
                        || preg_match('/[\x00-\x1F\x7F]/', $family) === 1) {
                        throw new InvalidManualContent('Font mark needs a font family.');
                    }

                    $byType[$type] = ['type' => $type, 'attrs' => ['family' => $family]];
                    continue 2;

                case 'size':
                    $size = $attrs['size'] ?? null;

                    if (!self::isAllowedFontSize($size)) {
                        throw new InvalidManualContent(sprintf('Size mark needs a whole number of pixels between %d and %d.', self::MIN_FONT_SIZE, self::MAX_FONT_SIZE));
                    }

                    $byType[$type] = ['type' => $type, 'attrs' => ['size' => $size]];
                    continue 2;

                case 'color':
                    $color = self::normalizeColor($attrs['color'] ?? null);

                    if ($color === null) {
                        throw new InvalidManualContent('Colour mark needs a #rrggbb colour.');
                    }

                    $byType[$type] = ['type' => $type, 'attrs' => ['color' => $color]];
                    continue 2;
            }

            throw new InvalidManualContent(sprintf('Mark type "%s" is not allowed.', mb_substr($type, 0, 40)));
        }

        $ordered = [];

        foreach (self::MARK_ORDER as $type) {
            if (isset($byType[$type])) {
                $ordered[] = $byType[$type];
            }
        }

        return $ordered;
    }

    private static function cleanText(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Newlines are hard_break nodes in ProseMirror, never characters;
        // tabs become spaces, other control characters go.
        $text = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $text);

        return preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
    }
}
