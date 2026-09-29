<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A provided fill value the render resolvers refuse — the non-rich-text
 * sibling of {@see InvalidRichTextValue}. It stays a
 * {@see BadRequestHttpException} so the web fill/download controllers keep
 * catching it exactly as before, while the API turns it into a structured
 * 400 `{error, code, ...context}` so consumers can react to the `code`
 * without parsing messages.
 *
 * Codes:
 * - `value_too_long` — a text exceeds the input's `maxLength` (context: `maxLength`)
 * - `invalid_value` — a text value of the wrong type / shape
 * - `invalid_image_value` — an image value of the wrong type / shape (incl. a
 *   transform without an `imageId`, or a pan sent in both px and ratio form)
 * - `image_transform_not_allowed` — a move / resize / rotate the slot does
 *   not permit (context: `transform`)
 * - `image_not_allowed` — the image does not exist, is trashed, belongs to
 *   another project or sits outside the slot's allowed folders
 * - `image_unreadable` — the picked file is not a readable raster image
 *
 * The resolvers add `inputId` / `imageInputId` to the context.
 */
final class InvalidExportValue extends BadRequestHttpException
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

    public static function valueTooLong(string $inputLabel, int $maxLength): self
    {
        return new self(
            'value_too_long',
            sprintf('Input "%s" exceeds max length of %d characters.', $inputLabel, $maxLength),
            ['maxLength' => $maxLength],
        );
    }

    public static function invalidValue(string $message): self
    {
        return new self('invalid_value', $message);
    }

    public static function invalidImageValue(string $message): self
    {
        return new self('invalid_image_value', $message);
    }

    public static function imageTransformNotAllowed(string $message, string $transform): self
    {
        return new self('image_transform_not_allowed', $message, ['transform' => $transform]);
    }

    public static function imageNotAllowed(string $message): self
    {
        return new self('image_not_allowed', $message);
    }

    public static function imageUnreadable(string $message): self
    {
        return new self('image_unreadable', $message);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return new self($this->errorCode, $this->getMessage(), [...$this->context, ...$context]);
    }
}
