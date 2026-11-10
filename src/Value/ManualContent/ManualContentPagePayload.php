<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Value\MockupPageLayout;

/**
 * The whole content page as the editor POSTs it — one JSON document
 * `{name, layout, segments, background}` (`background` null / absent = none). Strict: exactly one segment per layout slot,
 * a bounded size, every element valid.
 */
readonly final class ManualContentPagePayload
{
    public const int MAX_BYTES = 1_000_000;
    public const int MAX_ELEMENTS = 120;

    /**
     * @param list<ContentSegment> $segments
     */
    public function __construct(
        public string $name,
        public MockupPageLayout $layout,
        public array $segments,
        public null|ContentPageBackground $background = null,
    ) {
    }

    /**
     * @throws InvalidManualContent
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidManualContent('The page is too large.');
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidManualContent('The page is not valid JSON.');
        }

        if (!is_array($data)) {
            throw new InvalidManualContent('The page must be an object.');
        }

        $name = self::name($data['name'] ?? null);

        $layout = is_string($data['layout'] ?? null) ? MockupPageLayout::tryFrom($data['layout']) : null;

        if ($layout === null) {
            throw new InvalidManualContent('Unknown layout.');
        }

        $segments = ContentSegment::listFromArray($data['segments'] ?? null);

        if (count($segments) !== count($layout->slots())) {
            throw new InvalidManualContent('The page needs exactly one segment per layout slot.');
        }

        $elements = 0;

        foreach ($segments as $segment) {
            $elements += count($segment->elements);
        }

        if ($elements > self::MAX_ELEMENTS) {
            throw new InvalidManualContent('Too many elements on one page.');
        }

        return new self($name, $layout, $segments, ContentPageBackground::fromArray($data['background'] ?? null));
    }

    /**
     * The page name as every writer accepts it (editor save, "Přidat
     * stránku", rename): one plain line, not empty, at most 255 chars.
     *
     * @throws InvalidManualContent
     */
    public static function name(mixed $name): string
    {
        if (!is_string($name)) {
            throw new InvalidManualContent('The page needs a name.');
        }

        $name = ContentElements::plainLine($name);

        if ($name === '') {
            throw new InvalidManualContent('Vyplňte název stránky.');
        }

        if (mb_strlen($name) > ManualContentPage::MAX_NAME_LENGTH) {
            throw new InvalidManualContent('Název stránky je příliš dlouhý.');
        }

        return $name;
    }
}
