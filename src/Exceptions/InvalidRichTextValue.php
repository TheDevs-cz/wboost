<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Raised on the STRICT resolve path (API export) when a provided rich-text
 * value violates the contract — the rich-text sibling of the maxLength 400
 * and {@see ContainerOverflow}. The API ExportProcessors catch it and answer
 * a structured 400 `{error, code, ...context}` so consumers can react
 * programmatically (same pattern as `container_overflow`).
 *
 * The lenient web fill/download path never throws this: invalid styles are
 * stripped and the value degrades to plain text instead.
 */
#[WithHttpStatus(Response::HTTP_BAD_REQUEST)]
final class InvalidRichTextValue extends \Exception
{
    /**
     * @param array<string, mixed> $context extra fields merged into the structured 400 body
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function richTextNotAllowed(string $inputLabel): self
    {
        return new self(
            'rich_text_not_allowed',
            sprintf('Input "%s" does not allow rich text. Provide a plain string value.', $inputLabel),
        );
    }

    public static function invalidValue(string $inputLabel, string $reason): self
    {
        return new self(
            'invalid_rich_text',
            sprintf('Input "%s" received an invalid rich text value: %s', $inputLabel, $reason),
        );
    }

    /**
     * @param list<string> $allowedFamilies
     */
    public static function fontNotAllowed(string $inputLabel, string $fontFamily, array $allowedFamilies): self
    {
        return new self(
            'font_not_allowed',
            sprintf('Input "%s" uses font "%s" which is not allowed for this variant.', $inputLabel, $fontFamily),
            ['allowedFonts' => $allowedFamilies],
        );
    }

    public static function invalidColor(string $inputLabel, string $color): self
    {
        return new self(
            'invalid_color',
            sprintf('Input "%s" uses invalid color "%s". Use a hex color like "#c8102e".', $inputLabel, $color),
        );
    }
}
