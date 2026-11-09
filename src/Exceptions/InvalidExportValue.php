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
 * - `invalid_shape_color` — a shape colour that is not a `#rgb` / `#rrggbb` hex
 * - `shape_color_not_allowed` — a colour outside the shape's list while the
 *   admin did not allow any colour (context: `allowedColors`)
 * - `container_not_hidable` — `{hide: true}` addressed to a container the
 *   designer did not mark hidable (context: `containerId`)
 * - `unknown_element` — an offset addressed to an id that is neither an
 *   object nor a container of the variant (context: `elementId`)
 * - `move_not_allowed` — an offset on an element the user may not move, or
 *   on an axis it may not move along (context: `elementId`, `reason`:
 *   `not_allowed` | `in_container` | `hidden`, and `axis` for a disallowed axis)
 * - `offset_out_of_range` — an offset past the element's allowed range
 *   (context: `elementId`, `axis`, `min`, `max`, `value` — canvas px)
 *
 * The resolvers add `inputId` / `imageInputId` / `shapeInputId` to the context.
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

    public static function invalidShapeColor(string $shapeLabel): self
    {
        return new self(
            'invalid_shape_color',
            sprintf('Shape "%s" expects a hex colour (#rrggbb).', $shapeLabel),
        );
    }

    /**
     * @param list<string> $allowedColors lowercase `#rrggbb`, the designed colour first
     */
    public static function shapeColorNotAllowed(string $shapeLabel, string $color, array $allowedColors): self
    {
        return new self(
            'shape_color_not_allowed',
            sprintf('Colour %s is not allowed for shape "%s".', $color, $shapeLabel),
            ['allowedColors' => $allowedColors],
        );
    }

    public static function containerNotHidable(string $containerLabel, string $containerId): self
    {
        return new self(
            'container_not_hidable',
            sprintf('Container "%s" cannot be hidden — the designer did not mark it hidable.', $containerLabel),
            ['containerId' => $containerId],
        );
    }

    public static function unknownElement(string $elementId): self
    {
        return new self(
            'unknown_element',
            sprintf('The variant has no element "%s" to move.', $elementId),
            ['elementId' => $elementId],
        );
    }

    /**
     * @param 'not_allowed'|'in_container'|'hidden' $reason
     * @param null|'x'|'y' $axis set when only that axis is refused
     */
    public static function moveNotAllowed(string $elementId, string $reason, null|string $axis = null): self
    {
        $context = ['elementId' => $elementId, 'reason' => $reason];
        if ($axis !== null) {
            $context['axis'] = $axis;
        }

        return new self(
            'move_not_allowed',
            match (true) {
                $axis !== null => sprintf('Element "%s" may not be moved %s.', $elementId, $axis === 'x' ? 'horizontally' : 'vertically'),
                $reason === 'in_container' => sprintf('Element "%s" sits in a container — move the container instead.', $elementId),
                $reason === 'hidden' => sprintf('Element "%s" is hidden and cannot be moved.', $elementId),
                default => sprintf('Element "%s" may not be moved.', $elementId),
            },
            $context,
        );
    }

    /**
     * @param 'x'|'y' $axis
     */
    public static function offsetOutOfRange(string $elementId, string $axis, float $min, float $max, float $value): self
    {
        return new self(
            'offset_out_of_range',
            sprintf('Offset %s = %s of element "%s" is outside the allowed range [%s, %s].', $axis, $value, $elementId, $min, $max),
            ['elementId' => $elementId, 'axis' => $axis, 'min' => $min, 'max' => $max, 'value' => $value],
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return new self($this->errorCode, $this->getMessage(), [...$this->context, ...$context]);
    }
}
