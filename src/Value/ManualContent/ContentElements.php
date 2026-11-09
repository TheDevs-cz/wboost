<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Exceptions\InvalidManualContent;

/**
 * Decodes the `type`-discriminated element documents and holds the small
 * validation helpers the element classes share.
 */
final class ContentElements
{
    /**
     * @throws InvalidManualContent
     */
    public static function fromArray(mixed $data): ContentElement
    {
        if (!is_array($data)) {
            throw new InvalidManualContent('Element must be an object.');
        }

        return match ($data['type'] ?? null) {
            HeadingElement::TYPE => HeadingElement::fromArray($data),
            RichTextElement::TYPE => RichTextElement::fromArray($data),
            ImageElement::TYPE => ImageElement::fromArray($data),
            default => throw new InvalidManualContent('Unknown element type.'),
        };
    }

    public static function newId(): string
    {
        return Uuid::uuid7()->toString();
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManualContent
     */
    public static function id(array $data): string
    {
        $id = $data['id'] ?? null;

        if (!is_string($id) || preg_match(ContentElement::ID_PATTERN, $id) !== 1) {
            throw new InvalidManualContent('Element id is missing or malformed.');
        }

        return $id;
    }

    /**
     * One line of plain text: control characters (newlines included) become
     * spaces, the ends are trimmed.
     */
    public static function plainLine(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '');
    }
}
