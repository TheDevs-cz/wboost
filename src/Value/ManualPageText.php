<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * An admin's override of one manual page's texts. Either half may be null,
 * which means "keep the default" — the two are independent, so a page can
 * carry a custom heading with the stock description.
 */
readonly final class ManualPageText
{
    public function __construct(
        public null|string $title,
        public null|string $description,
    ) {
    }

    /**
     * Blank input is stored as null: an empty override would render an empty
     * page, and "clear the field" reads as "give me the default back".
     */
    public static function fromInput(null|string $title, null|string $description): self
    {
        return new self(
            self::normalize($title),
            self::normalize($description),
        );
    }

    /**
     * Defensive on purpose — a hand-edited row must never fatal a manual.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;

        return new self(
            is_string($title) ? self::normalize($title) : null,
            is_string($description) ? self::normalize($description) : null,
        );
    }

    /**
     * @return array{title: null|string, description: null|string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
        ];
    }

    /**
     * Nothing overridden — the entity drops such an entry instead of storing
     * a row that says "no change".
     */
    public function isEmpty(): bool
    {
        return $this->title === null && $this->description === null;
    }

    private static function normalize(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }
}
