<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * A parsed design document — the public authoring interface of the MCP server
 * (plan §0.3: agents write this, never raw Fabric JSON).
 *
 * Semantics that shape everything downstream:
 *
 * - **Full-document.** `set_design` always replaces the whole design; there are
 *   no patch operations, because patch sequences accumulate drift and are not
 *   reproducible (§0.4).
 * - **Element order IS the stack order**, bottom → top (§3.4). Container
 *   elements are definitions and take no slot in it.
 * - **Slug ids carry identity across replacements.** The compiler maps a slug
 *   to the `inputId` UUID the variant already uses and mints a fresh one
 *   otherwise — that is what makes editing safe.
 *
 * Construction is public (VO house style) but **{@see DslParser} is the only
 * validating entry point.** Building one by hand — the decompiler (S4-T5) does
 * exactly that — bypasses every check, which is why its round-trip test
 * re-parses its own output.
 */
readonly final class DesignDocument
{
    public function __construct(
        public CanvasSpec $canvas,
        /**
         * In stack order, bottom → top.
         *
         * @var list<DesignElement>
         */
        public array $elements,
    ) {
    }

    /**
     * The single background layer, if the document declares one as an element.
     *
     * Note this is NOT the same as {@see CanvasSpec::$backgroundImageAssetId}:
     * the canvas-level shorthand is the other way to say it, and the parser
     * refuses a document that uses both.
     */
    public function backgroundElement(): null|BackgroundElement
    {
        foreach ($this->elements as $element) {
            if ($element instanceof BackgroundElement) {
                return $element;
            }
        }

        return null;
    }

    public function element(string $id): null|DesignElement
    {
        foreach ($this->elements as $element) {
            if ($element->id === $id) {
                return $element;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function elementIds(): array
    {
        return array_map(static fn (DesignElement $element): string => $element->id, $this->elements);
    }

    /**
     * @return list<TextElement>
     */
    public function textElements(): array
    {
        return array_values(array_filter(
            $this->elements,
            static fn (DesignElement $element): bool => $element instanceof TextElement,
        ));
    }

    /**
     * @return list<ImageElement>
     */
    public function imageElements(): array
    {
        return array_values(array_filter(
            $this->elements,
            static fn (DesignElement $element): bool => $element instanceof ImageElement,
        ));
    }

    /**
     * @return list<ContainerElement>
     */
    public function containerElements(): array
    {
        return array_values(array_filter(
            $this->elements,
            static fn (DesignElement $element): bool => $element instanceof ContainerElement,
        ));
    }

    /**
     * The elements that actually become Fabric objects, in stack order —
     * everything except container definitions.
     *
     * @return list<TextElement|ImageElement|BackgroundElement>
     */
    public function drawableElements(): array
    {
        /** @var list<TextElement|ImageElement|BackgroundElement> $drawable */
        $drawable = array_values(array_filter(
            $this->elements,
            static fn (DesignElement $element): bool => !$element instanceof ContainerElement,
        ));

        return $drawable;
    }

    /**
     * Canonical wire form: every optional key emitted with its resolved value,
     * so `DslParser::parse($doc->toArray())` returns an equal document. That
     * identity is what lets the decompiler (S4-T5) build VOs and serialize
     * them without owning a second copy of the grammar.
     *
     * @return array{canvas: array{width: int, height: int, background: null|array{image: null|string, fill: null|string}}, elements: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canvas' => $this->canvas->toArray(),
            'elements' => array_map(
                static fn (DesignElement $element): array => $element->toArray(),
                $this->elements,
            ),
        ];
    }
}
