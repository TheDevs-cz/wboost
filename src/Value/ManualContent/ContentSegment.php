<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use WBoost\Web\Exceptions\InvalidManualContent;

/**
 * One layout slot of a content page: its elements top to bottom. Segment i
 * fills `MockupPageLayout::slots()[i]`.
 */
readonly final class ContentSegment
{
    /**
     * @param list<ContentElement> $elements
     */
    public function __construct(
        public ContentVerticalAlign $verticalAlign = ContentVerticalAlign::Top,
        public array $elements = [],
    ) {
    }

    /**
     * @throws InvalidManualContent
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new InvalidManualContent('Segment must be an object.');
        }

        $align = $data['verticalAlign'] ?? ContentVerticalAlign::Top->value;
        $verticalAlign = is_string($align) ? ContentVerticalAlign::tryFrom($align) : null;

        if ($verticalAlign === null) {
            throw new InvalidManualContent('Unknown segment vertical alignment.');
        }

        $elements = $data['elements'] ?? [];

        if (!is_array($elements) || !array_is_list($elements)) {
            throw new InvalidManualContent('Segment elements must be a list.');
        }

        return new self(
            $verticalAlign,
            array_map(ContentElements::fromArray(...), $elements),
        );
    }

    /**
     * @return list<self>
     * @throws InvalidManualContent
     */
    public static function listFromArray(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new InvalidManualContent('Segments must be a list.');
        }

        return array_map(self::fromArray(...), $data);
    }

    /**
     * @param list<self> $segments
     * @return list<array{verticalAlign: string, elements: list<array<string, mixed>>}>
     */
    public static function listToArray(array $segments): array
    {
        return array_map(static fn (self $segment): array => $segment->toArray(), $segments);
    }

    /**
     * @param list<ContentElement> $elements
     */
    public function withElements(array $elements): self
    {
        return new self($this->verticalAlign, $elements);
    }

    /**
     * @return array{verticalAlign: string, elements: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'verticalAlign' => $this->verticalAlign->value,
            'elements' => array_map(static fn (ContentElement $element): array => $element->toArray(), $this->elements),
        ];
    }
}
